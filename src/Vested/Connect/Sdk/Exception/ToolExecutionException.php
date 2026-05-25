<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Exception;

/**
 * Thrown internally inside a worker when a customer tool handler
 * crashes. The dispatcher catches this and converts it into a
 * ToolCallResponse{error} — never crashes the worker.
 */
final class ToolExecutionException extends ConnectorException
{
}
