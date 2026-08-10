<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

/**
 * Implemented by a connector that wants per-user credentials.
 *
 * The platform cannot open a sealed credential — only this worker can — so
 * every question about whether a user's credentials work is answered here.
 *
 * `$credential` arrives already decrypted and already verified as belonging to
 * the calling user; the SDK does that before this method is entered.
 */
interface UserCredentialHandler
{
    /**
     * Try the credentials against the real system.
     *
     * @param  array<string, string>  $credential  the decrypted field map
     */
    public function validate(CredentialContext $ctx, array $credential): CredentialValidation;

    /**
     * Tear down any remote session for these credentials. Best-effort: the
     * platform deletes its copy regardless of what happens here.
     *
     * @param  array<string, string>  $credential
     */
    public function revoke(CredentialContext $ctx, array $credential): void;
}
