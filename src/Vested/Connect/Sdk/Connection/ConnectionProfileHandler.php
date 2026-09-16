<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

use Vested\Connect\Sdk\Credential\CredentialValidation;

/**
 * Answers the platform's questions about one tenant connection profile. The
 * credential arrives already opened and identity-checked.
 *
 * - validate: try the credential against the real system (fields profiles are
 *   validated before the platform stores them).
 * - listResources: what this credential can reach — GA4 properties, Ads
 *   customers, sites — so a tenant admin picks instead of typing ids.
 */
interface ConnectionProfileHandler
{
    /** @param array<string, string> $credential */
    public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation;

    /**
     * @param  array<string, string>  $credential
     * @return list<ConnectionResource>
     */
    public function listResources(ConnectionOpContext $ctx, array $credential): array;
}
