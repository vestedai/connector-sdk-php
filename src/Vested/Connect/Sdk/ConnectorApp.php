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

    public function runSwooleDaemon(string $token, string $hubAddr, bool $insecure = false): int
    {
        $parts = explode(':', $hubAddr);
        $host  = $parts[0];
        $port  = (int) ($parts[1] ?? 4443);
        $grpc  = new \Vested\Connect\Sdk\Swoole\GrpcClient(
            host: $host, port: $port, token: $token, insecure: $insecure,
        );
        $daemon = new \Vested\Connect\Sdk\Swoole\Daemon($this, $grpc, $this->logger);
        return $daemon->run();
    }
}
