<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

use RuntimeException;

final class CredentialException extends RuntimeException
{
    public static function identityMismatch(string $expected, string $actual): self
    {
        // Deliberately does not include the decrypted payload. This is a
        // security event: an envelope sealed for one identity arrived on a call
        // made by another.
        return new self(
            "credential envelope identity mismatch: envelope is bound to '{$actual}', "
            . "invocation is '{$expected}'"
        );
    }

    public static function decryptFailed(string $detail = 'credential envelope failed to decrypt or authenticate'): self
    {
        return new self($detail);
    }

    public static function unsupportedAlg(string $alg): self
    {
        return new self("unsupported credential envelope algorithm '{$alg}'");
    }
}
