<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Schema;

use Vested\Connect\Sdk\Schema\JsonSchemaValidator;

it('accepts a valid payload', function () {
    $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
    $v = new JsonSchemaValidator($schema);
    $errs = $v->validate(['q' => 'hello']);
    expect($errs)->toBeEmpty();
});

it('rejects a payload missing a required field', function () {
    $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
    $v = new JsonSchemaValidator($schema);
    $errs = $v->validate([]);
    expect($errs)->not->toBeEmpty();
    expect(implode("\n", $errs))->toContain('q');
});

it('accepts a nested-properties schema (the common real-world shape)', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]]],
        ],
    ];
    $v = new JsonSchemaValidator($schema);
    expect($v->validate(['items' => [['sku' => 'A-1']]]))->toBeEmpty();
});

it('throws on a structurally invalid schema document', function () {
    expect(fn () => new JsonSchemaValidator(['type' => 'NotAValidType']))
        ->toThrow(\InvalidArgumentException::class);
});
