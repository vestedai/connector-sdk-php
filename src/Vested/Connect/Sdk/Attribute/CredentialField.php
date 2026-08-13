<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * Declares one field of the credential form. Repeatable: apply one per field,
 * on the same class that carries #[CredentialSchema]. Fields reach the platform
 * in declaration order, which is the order the user sees them.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class CredentialField
{
    /** Canonical values for $type. The core renders "password" masked. */
    public const TYPES = ['text', 'password', 'url', 'select'];

    /**
     * @param  string        $key          map key in the sealed field map — what the handler and
     *                                     tools read, e.g. $credential['username']
     * @param  string        $label        shown to the user; defaults to $key when blank
     * @param  string        $type         one of self::TYPES
     * @param  bool          $required     whether the user must supply a value
     * @param  string        $placeholder  optional placeholder text
     * @param  list<string>  $options      choices for a "select" field; ignored for every other type
     */
    public function __construct(
        public string $key,
        public string $label = '',
        public string $type = 'text',
        public bool $required = true,
        public string $placeholder = '',
        public array $options = [],
    ) {}
}
