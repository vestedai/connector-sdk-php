<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Schema;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Thin wrapper around opis/json-schema for validating tool args + results.
 * Throws on construction if the schema document itself is malformed
 * (so bad schemas surface at boot rather than at the first tool call).
 *
 * @internal
 */
final class JsonSchemaValidator
{
    private object $schemaDoc;

    /**
     * @param  array<string, mixed>  $schema  A JSON-Schema document as PHP array.
     */
    public function __construct(array $schema)
    {
        $deep = json_decode((string) json_encode($schema), associative: false);
        if (! is_object($deep)) {
            throw new InvalidArgumentException('JSON schema must encode to a JSON object');
        }
        $this->schemaDoc = $deep;

        $v = new Validator();
        try {
            $v->validate(new \stdClass(), $this->schemaDoc);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid JSON Schema document: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>  Empty list when the payload is valid; otherwise human-readable error messages.
     */
    public function validate(?array $payload): array
    {
        $v = new Validator();
        if ($payload === null) {
            $doc = null;
        } elseif ($payload === []) {
            // Empty PHP array encodes as JSON array []; cast to object so it
            // encodes as {} and the schema sees an object, not an array.
            $doc = new \stdClass();
        } else {
            $doc = json_decode((string) json_encode($payload), associative: false);
        }
        $result = $v->validate($doc, $this->schemaDoc);
        if ($result->isValid()) {
            return [];
        }
        $err = $result->error();
        if ($err === null) {
            return ['validation failed'];
        }
        $formatter = new ErrorFormatter();
        $msgs = $formatter->formatFlat($err);
        return array_values($msgs);
    }
}
