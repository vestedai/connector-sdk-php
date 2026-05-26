<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Agent\AgentRegistry;
use Vested\Connect\Sdk\Scanner\ReflectionScanner;
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
        return $this;
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
