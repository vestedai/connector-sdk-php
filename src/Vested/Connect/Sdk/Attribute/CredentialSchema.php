<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * Marks the class implementing UserCredentialHandler as this connector's
 * credential handler, and declares the form the platform renders for the user.
 *
 * Declaring one is what turns per-user credentials on: a connector whose
 * handler carries no #[CredentialSchema] registers no credential_schema, stays
 * hidden from the credential UI, and has none of its tools gated.
 *
 * Apply once, on the handler class, together with one #[CredentialField] per
 * field of the form.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CredentialSchema
{
    /** Canonical values for $kind. The core rejects anything else. */
    public const KINDS = ['basic', 'token', 'custom'];

    /**
     * @param  string  $kind      one of self::KINDS
     * @param  string  $title     form heading shown to the user, e.g. "Al-Saif ERP account"
     * @param  string  $helpText  optional guidance shown under the heading
     */
    public function __construct(
        public string $kind = 'basic',
        public string $title = '',
        public string $helpText = '',
    ) {}
}
