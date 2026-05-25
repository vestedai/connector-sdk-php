<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Exception;

/**
 * Thrown by ConnectorApp::build() / AgentBuilder::toDeclaration() when
 * the customer's declarations are internally inconsistent (duplicate
 * instruction positions, missing schema file, namespace mismatch, etc.).
 */
final class ConfigException extends ConnectorException
{
}
