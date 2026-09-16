<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Attribute;

use Attribute;

/**
 * Declares a kind of account a TENANT links once and binds to agents (SaaS
 * Provided Connectors). Put it on a ConnectionProfileHandler class ITSELF: PHP
 * does not inherit class attributes.
 *
 * oauth2 profiles name a provider the platform has configured and the scopes
 * requirements may ask for; the platform runs the consent flow and hands this
 * worker a short-lived access token. fields profiles declare their form with
 * #[ConnectionField]. Neither ever names endpoints or client secrets.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ConnectionProfile
{
    public const AUTH_KINDS = ['oauth2', 'fields'];

    /** @param list<string> $scopes */
    public function __construct(
        public string $key,
        public string $title,
        public string $authKind,
        public string $oauthProvider = '',
        public array $scopes = [],
        public string $helpText = '',
        public bool $resourceDiscoverable = false,
        public string $resourceTitle = '',
    ) {}
}
