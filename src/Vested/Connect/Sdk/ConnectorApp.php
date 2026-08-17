<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Agent\AgentRegistry;
use Vested\Connect\Sdk\Scanner\DeclarationFactory;
use Vested\Connect\Sdk\Scanner\ReflectionScanner;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;
use Vested\Connect\Sdk\Tool\ToolRegistry;

/**
 * Public facade for the SDK. Customers build one of these in their
 * bootstrap.php and return it; the CLI loads + runs it.
 */
final class ConnectorApp
{
    private LoggerInterface $logger;
    private ?object $tracer = null;
    private int $workerPoolSize = 4;
    /** @var array<string, AgentBuilder> */
    private array $agents = [];
    private ?AgentRegistry $builtAgents = null;
    private ?ToolRegistry $builtTools = null;

    /** Null unless the connector opted into per-user credentials. */
    private ?Credential\UserCredentialHandler $credentialHandler = null;

    /** @var list<string> PKCS#8 PEM private keys, newest first. */
    private array $credentialPrivateKeys = [];

    /** Null unless the connector fronts a relational database. */
    private ?RelationalSchemaProvider $relationalProvider = null;

    /**
     * Wire-shape declarations derived from the attributes on the handler /
     * provider classes. Null = nothing declared, which is what tells the
     * platform to leave this connector alone.
     *
     * @var array{kind: string, title: string, help_text: string, fields: list<array{key: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>}|null
     */
    private ?array $credentialSchemaDeclaration = null;

    /** @var array{engine: string, describe_tool: string, query_tool: string, sql_arg: string, default_scope: string, scopes: list<string>}|null */
    private ?array $relationalSourceDeclaration = null;

    /** Assigned by the hub at HelloAck; part of the envelope AAD. */
    private string $connectorId = '';

    private function __construct()
    {
        $this->logger = new NullLogger();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function withTracer(object $tracer): self
    {
        $this->tracer = $tracer;
        return $this;
    }

    public function withWorkerPoolSize(int $n): self
    {
        if ($n < 1) {
            throw new Exception\ConfigException("worker pool size must be >= 1, got {$n}");
        }
        $this->workerPoolSize = $n;
        return $this;
    }

    /** Start declaring a new agent; returns the AgentBuilder for chaining. */
    public function agent(string $key): AgentBuilder
    {
        if (isset($this->agents[$key])) {
            throw new Exception\ConfigException("agent '{$key}' already declared");
        }
        $b = new AgentBuilder($key);
        $this->agents[$key] = $b;
        $b->__setParentApp($this);
        return $b;
    }

    /** Called by AgentBuilder::endAgent(); returns self for chaining. */
    public function __closeCurrentAgent(): self
    {
        return $this;
    }

    public function scanNamespace(string $namespace, string $dir, ?ContainerInterface $container = null): self
    {
        $result = (new ReflectionScanner($container))->scan($namespace, $dir, $this->agents);
        foreach ($result->agents as $a) {
            $decl = $a->toDeclaration();
            if (isset($this->agents[$decl['key']])) {
                throw new Exception\ConfigException("agent '{$decl['key']}' already declared (scanner)");
            }
            $this->agents[$decl['key']] = $a;
        }
        return $this;
    }

    public function build(): self
    {
        $this->builtAgents = new AgentRegistry($this->agents);
        $this->builtTools  = ToolRegistry::fromAgents($this->agents);
        $this->finalizeDeclarations();
        return $this;
    }

    /**
     * Derive the credential / relational declarations from the attributes on
     * the classes the connector registered, and validate them.
     *
     * Called from build(), and again from any with*() that lands AFTER build()
     * — otherwise a handler registered last would be carried into Register with
     * its declaration never derived and never validated, which is exactly the
     * silent-disablement failure these declarations exist to close.
     */
    private function finalizeDeclarations(): void
    {
        $this->credentialSchemaDeclaration = null;
        if ($this->credentialHandler !== null) {
            $this->credentialSchemaDeclaration = DeclarationFactory::credentialSchemaFrom($this->credentialHandler);
        }

        $this->relationalSourceDeclaration = null;
        if ($this->relationalProvider !== null) {
            $decl = DeclarationFactory::relationalSourceFrom($this->relationalProvider);
            $this->validateRelationalSourceTools($decl);
            $this->relationalSourceDeclaration = $decl;
        }
    }

    /**
     * Cross-check a relational source against the tools this connector actually
     * declares: both tool keys must exist, and sql_arg must name an argument of
     * the query tool.
     *
     * Nothing downstream catches these. The core validates relational_source
     * for non-emptiness and a namespace prefix only — it cannot know which
     * tools exist or what arguments they take. So a one-character typo in the
     * query tool key, or a sqlArg whose case does not match the input schema,
     * is ACCEPTED at registration: the gate then governs a tool key nothing
     * answers to and reads an argument that is always null — authorizing an
     * empty string — while the REAL query tool runs ungoverned. That is silent,
     * and it is the failure this layer exists to prevent, so it is refused at
     * startup instead.
     *
     * @param  array{engine: string, describe_tool: string, query_tool: string, sql_arg: string}  $decl
     */
    private function validateRelationalSourceTools(array $decl): void
    {
        /** @var array<string, array<string, mixed>> $tools */
        $tools = [];
        foreach (($this->builtAgents?->declarations() ?? []) as $agent) {
            foreach (($agent['tools'] ?? []) as $tool) {
                $tools[(string) $tool['key']] = $tool;
            }
        }

        $keys = array_keys($tools);
        sort($keys);
        $declared = $keys === [] ? 'none' : implode(', ', $keys);

        if (! isset($tools[$decl['describe_tool']])) {
            throw new Exception\ConfigException(
                "relational source declares describeTool '{$decl['describe_tool']}' but this "
                . "connector declares no such tool (declared tools: {$declared})"
            );
        }

        if (! isset($tools[$decl['query_tool']])) {
            throw new Exception\ConfigException(
                "relational source declares queryTool '{$decl['query_tool']}' but this "
                . "connector declares no such tool (declared tools: {$declared})"
            );
        }

        $args = self::wireArgumentNames($tools[$decl['query_tool']]);
        if (! in_array($decl['sql_arg'], $args, true)) {
            throw new Exception\ConfigException(
                "relational source declares sqlArg '{$decl['sql_arg']}' but tool "
                . "'{$decl['query_tool']}' has no such argument (its arguments are: "
                . ($args === [] ? 'none' : implode(', ', $args)) . '). '
                . "The name must match the tool's input schema exactly, including case."
            );
        }
    }

    /**
     * The argument names of a tool AS THEY APPEAR ON THE WIRE.
     *
     * Read from the declared input schema, which this SDK serializes verbatim
     * into ToolDecl.input_schema_json — so its `properties` keys are the wire
     * names by construction. Never from the handler's own PHP signature: a
     * Closure's parameter names never reach the wire at all (arguments arrive
     * as one assoc array keyed by these schema properties), so validating
     * against them would accept a declaration that reads null at gate time,
     * which is the exact bug this check exists to catch.
     *
     * @param  array<string, mixed>  $tool
     * @return list<string>
     */
    private static function wireArgumentNames(array $tool): array
    {
        $schema = $tool['input_schema_json'] ?? null;
        if (! is_array($schema)) {
            return [];
        }

        // Resolve a root $ref one level into definitions/$defs. Hand-authored
        // schemas do use that shape, and guessing wrong here would reject a
        // perfectly valid connector at startup.
        $ref = $schema['$ref'] ?? null;
        if (! isset($schema['properties']) && is_string($ref)) {
            foreach (['definitions', '$defs'] as $bucket) {
                $prefix = "#/{$bucket}/";
                if (! str_starts_with($ref, $prefix) || ! is_array($schema[$bucket] ?? null)) {
                    continue;
                }
                $target = $schema[$bucket][substr($ref, strlen($prefix))] ?? null;
                if (is_array($target)) {
                    $schema = $target;
                    break;
                }
            }
        }

        $properties = $schema['properties'] ?? null;

        return is_array($properties) ? array_map(strval(...), array_keys($properties)) : [];
    }

    public function logger(): LoggerInterface { return $this->logger; }
    public function tracer(): ?object { return $this->tracer; }
    public function workerPoolSize(): int { return $this->workerPoolSize; }

    public function agents(): AgentRegistry
    {
        if ($this->builtAgents === null) {
            throw new Exception\ConfigException('ConnectorApp::build() must be called before agents()');
        }
        return $this->builtAgents;
    }

    /**
     * Register the handler that answers credential lifecycle ops for this
     * connector, plus the private keys that open sealed envelopes.
     *
     * Keys are a RING, newest first: during a key rotation the platform seals
     * with the new key while envelopes saved earlier still carry the old one,
     * so a worker holding both keeps working through the overlap.
     *
     * Reads VESTED_CREDENTIAL_PRIVATE_KEY (or _FILE, a path) when no keys are
     * passed explicitly. Multiple keys may be separated by a blank line.
     *
     * @param  list<string>  $privateKeyPems  PKCS#8 PEM keys, newest first
     */
    public function withCredentialHandler(
        Credential\UserCredentialHandler $handler,
        array $privateKeyPems = [],
    ): self {
        $this->credentialHandler = $handler;
        $this->credentialPrivateKeys = $privateKeyPems !== []
            ? array_values($privateKeyPems)
            : self::credentialKeysFromEnv();

        if ($this->credentialPrivateKeys === []) {
            throw new Exception\ConfigException(
                'A credential handler was registered but no private key was found. '
                . 'Pass the PEM(s) explicitly or set VESTED_CREDENTIAL_PRIVATE_KEY / '
                . 'VESTED_CREDENTIAL_PRIVATE_KEY_FILE. Without the key the worker cannot '
                . 'read any user credential and every check would fail.'
            );
        }

        if ($this->builtTools !== null) {
            $this->finalizeDeclarations();
        }

        return $this;
    }

    /**
     * Register the provider that describes this connector's relational source.
     *
     * The declaration itself comes from the #[RelationalSource] attribute on
     * $provider's class — engine, the two tool keys and the SQL argument name
     * are all specific to this connector, so they belong in connector-owned
     * code rather than in a call the SDK could make on any provider.
     *
     * The provider instance is kept (not just its declaration) because the
     * catalog fingerprint is read from it LIVE each time Register is built.
     */
    public function withRelationalSchemaProvider(RelationalSchemaProvider $provider): self
    {
        $this->relationalProvider = $provider;

        if ($this->builtTools !== null) {
            $this->finalizeDeclarations();
        }

        return $this;
    }

    public function credentialHandler(): ?Credential\UserCredentialHandler
    {
        return $this->credentialHandler;
    }

    public function relationalSchemaProvider(): ?RelationalSchemaProvider
    {
        return $this->relationalProvider;
    }

    /**
     * The credential form this connector declares, or null when it declares
     * none — in which case no credential_schema goes on Register and the
     * platform leaves the connector out of the credential UI entirely.
     *
     * @return array{kind: string, title: string, help_text: string, fields: list<array{key: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>}|null
     */
    public function credentialSchemaDeclaration(): ?array
    {
        return $this->credentialSchemaDeclaration;
    }

    /**
     * The relational source this connector declares, or null when it fronts no
     * database. Carries no fingerprint: that is read live at Register time.
     *
     * @return array{engine: string, describe_tool: string, query_tool: string, sql_arg: string, default_scope: string, scopes: list<string>}|null
     */
    public function relationalSourceDeclaration(): ?array
    {
        return $this->relationalSourceDeclaration;
    }

    /** @return list<string> */
    public function credentialPrivateKeys(): array
    {
        return $this->credentialPrivateKeys;
    }

    /**
     * The connector id assigned by the hub at HelloAck. Used as part of the
     * envelope AAD, so it must be the hub's value and not anything local.
     */
    public function connectorId(): string
    {
        return $this->connectorId;
    }

    public function setConnectorId(string $id): void
    {
        $this->connectorId = $id;
    }

    /** @return list<string> */
    private static function credentialKeysFromEnv(): array
    {
        $inline = getenv('VESTED_CREDENTIAL_PRIVATE_KEY');
        $path   = getenv('VESTED_CREDENTIAL_PRIVATE_KEY_FILE');

        $raw = '';
        if (is_string($inline) && $inline !== '') {
            $raw = $inline;
        } elseif (is_string($path) && $path !== '' && is_readable($path)) {
            $raw = (string) file_get_contents($path);
        }

        if (trim($raw) === '') {
            return [];
        }

        // Split on a blank line so a keyring can live in one variable.
        $parts = preg_split('/\n\s*\n/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    public function tools(): ToolRegistry
    {
        if ($this->builtTools === null) {
            throw new Exception\ConfigException('ConnectorApp::build() must be called before tools()');
        }
        return $this->builtTools;
    }

    /**
     * Long-running supervisor: runs Daemon sessions in a loop, reconnecting
     * with exponential backoff on transient errors. Exits cleanly only on
     * SIGTERM/SIGINT or terminal config errors (token rejected).
     *
     * Why a supervisor: hub restarts (deploys, scaling, node maintenance)
     * are routine. A bare Daemon::run() would exit on the first disconnect
     * and rely on the pod orchestrator to restart it, which introduces a
     * 5–15s cold-start gap and trips CrashLoopBackOff if the hub is down
     * for more than ~5 minutes during a longer rollout. In-process reconnect
     * keeps the worker warm and recovers in ~1s.
     *
     * The SignalHandler is installed at the supervisor level (not per
     * session) so a SIGTERM that arrives during the inter-attempt backoff
     * sleep is still caught — otherwise k8s graceful-stop windows could
     * race the sleep and leak in-flight work past terminationGracePeriod.
     */
    public function runSwooleDaemon(string $token, string $hubAddr, bool $insecure = false): int
    {
        $parts = explode(':', $hubAddr);
        $host  = $parts[0];
        $port  = (int) ($parts[1] ?? 4443);

        $signals = new \Vested\Connect\Sdk\Swoole\SignalHandler();
        $signals->install();
        $backoff = new \Vested\Connect\Sdk\Hub\Backoff();

        try {
            while (true) {
                if ($signals->shouldExit()) {
                    return 0;
                }

                $grpc = new \Vested\Connect\Sdk\Swoole\GrpcClient(
                    host: $host, port: $port, token: $token, insecure: $insecure,
                );
                $daemon = new \Vested\Connect\Sdk\Swoole\Daemon(
                    $this, $grpc, $this->logger, signals: $signals,
                );

                $exit = $daemon->run();

                // PHPStan can't model Swoole's Process::signal callbacks mutating
                // SignalHandler::$shouldExit from outside, so it infers this check
                // as always-false based on the property's declared initial value.
                // At runtime the closure registered in SignalHandler::install()
                // flips it on SIGTERM/SIGINT, so this branch IS reachable.
                /** @phpstan-ignore-next-line booleanNot.alwaysTrue, if.alwaysFalse */
                if ($signals->shouldExit()) {
                    // Graceful shutdown via signal — exit cleanly regardless
                    // of the Daemon's return code (a stream may have closed
                    // mid-shutdown and produced a non-zero code).
                    return 0;
                }
                if ($exit === 78) {
                    // EX_CONFIG: token rejected. Retrying won't help; let the
                    // operator surface the issue.
                    return 78;
                }

                // Transient. A session that completed handshake (hub deploy
                // mid-stream) should retry quickly; one that died before
                // handshake (hub down, network broken) should back off.
                if ($daemon->handshakeCompleted()) {
                    $backoff->reset();
                }
                $delayMs = $backoff->next();
                $this->logger->warning('hub session ended, reconnecting', [
                    'delay_ms'            => $delayMs,
                    'handshake_completed' => $daemon->handshakeCompleted(),
                    'last_exit'           => $exit,
                ]);
                \Swoole\Coroutine::sleep($delayMs / 1000);
            }
        } finally {
            $signals->uninstall();
        }
    }
}
