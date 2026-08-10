<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

/**
 * A handler's verdict on a user's credentials.
 *
 * `display` is shown to the user in the platform UI, so it must contain only
 * non-secret facts — an account name or role, never the credential itself.
 */
final readonly class CredentialValidation
{
    /**
     * @param  array<string, string>  $display
     */
    private function __construct(
        public bool $ok,
        public string $error,
        public array $display,
    ) {}

    /** @param array<string, string> $display */
    public static function ok(array $display = []): self
    {
        return new self(true, '', $display);
    }

    /**
     * @param  string  $userFacingMessage  Shown verbatim to the user. Do not
     *         include the credential, a stack trace, or internal hostnames.
     */
    public static function failed(string $userFacingMessage): self
    {
        return new self(false, $userFacingMessage, []);
    }
}
