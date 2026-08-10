<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

use RuntimeException;

/**
 * No sealed credential was forwarded for this tool call.
 *
 * Defensive: when a connector declares a credential schema the platform gates
 * dispatch, so a gated tool should never run without one. Reaching this means
 * either the connector declares no schema (and the tool should not be asking)
 * or the gate is misconfigured — both worth failing loudly rather than
 * silently proceeding without an identity.
 */
final class CredentialUnavailable extends RuntimeException {}
