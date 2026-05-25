<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Exception;

/**
 * Thrown when the connector JWT is missing, malformed, or rejected by
 * the hub. Bootstrap exits 78 (EX_CONFIG) on this so the supervisor
 * doesn't auto-restart with a still-bad token.
 *
 * __toString() redacts the token to first/last 4 chars to keep it out
 * of crash logs.
 */
final class TokenException extends ConnectorException
{
    public function __construct(string $message, private readonly ?string $token = null)
    {
        parent::__construct($message);
    }

    public function __toString(): string
    {
        $base = parent::__toString();
        if ($this->token === null) {
            return $base;
        }
        if (strlen($this->token) < 10) {
            return $base . "\ntoken: [redacted-short]";
        }
        $redacted = substr($this->token, 0, 4) . '…' . substr($this->token, -4);
        return $base . "\ntoken: {$redacted}";
    }
}
