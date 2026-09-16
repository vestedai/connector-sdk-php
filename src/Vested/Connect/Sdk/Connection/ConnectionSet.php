<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

/** The tenant connections bound to the calling agent for this one tool call. */
final class ConnectionSet
{
    /** @param list<Connection> $connections */
    public function __construct(private readonly array $connections = []) {}

    public function has(string $profile): bool
    {
        return $this->all($profile) !== [];
    }

    /** The single connection of $profile; for a `many` requirement use all() or byLabel(). */
    public function one(string $profile): Connection
    {
        $matches = $this->all($profile);

        if ($matches === []) {
            throw new ConnectionUnavailable(
                "No '{$profile}' connection was supplied for this tool call. The agent may not be activated "
                . 'with one, or this connector is not running as a platform connector.'
            );
        }
        if (count($matches) > 1) {
            throw new ConnectionUnavailable(
                'This tool call carries ' . count($matches) . " '{$profile}' connections; use all('{$profile}') "
                . "or byLabel('{$profile}', …)."
            );
        }

        return $matches[0];
    }

    /** @return list<Connection> */
    public function all(string $profile): array
    {
        return array_values(array_filter($this->connections, fn (Connection $c) => $c->profile === $profile));
    }

    public function byLabel(string $profile, string $label): Connection
    {
        foreach ($this->all($profile) as $c) {
            if ($c->label === $label) {
                return $c;
            }
        }

        throw new ConnectionUnavailable("No '{$profile}' connection labelled '{$label}' is bound to this agent.");
    }
}
