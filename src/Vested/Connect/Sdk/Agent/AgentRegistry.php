<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Agent;

use Vested\Connect\Sdk\Exception\ConfigException;

/**
 * Immutable registry of agent declarations, plus a deterministic fingerprint
 * over the canonical declaration set for the hub's fingerprint-short-circuit.
 */
final class AgentRegistry
{
    /** @var list<array<string,mixed>> */
    private readonly array $declarations;
    private readonly string $fingerprint;

    /** @param iterable<AgentBuilder> $agents */
    public function __construct(iterable $agents)
    {
        $declarations = [];
        $seenKeys = [];
        foreach ($agents as $a) {
            $decl = $a->toDeclaration();
            if (isset($seenKeys[$decl['key']])) {
                throw new ConfigException("duplicate agent key '{$decl['key']}'");
            }
            $seenKeys[$decl['key']] = true;
            $declarations[] = $decl;
        }
        usort($declarations, fn (array $x, array $y) => strcmp($x['key'], $y['key']));
        $this->declarations = $declarations;
        $this->fingerprint = hash('sha256', json_encode($declarations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array<string,mixed>> */
    public function declarations(): array { return $this->declarations; }

    public function fingerprint(): string { return $this->fingerprint; }
}
