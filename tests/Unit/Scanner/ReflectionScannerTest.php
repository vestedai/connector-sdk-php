<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Scanner;

use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Scanner\ReflectionScanner;

it('discovers an attribute-decorated agent + its tool from a namespace+dir', function () {
    $scanner = new ReflectionScanner();
    $result = $scanner->scan(
        'Vested\\Connect\\Sdk\\Tests\\Fixtures\\ExampleAgents',
        __DIR__ . '/../../Fixtures/ExampleAgents',
    );

    expect($result->agents)->toHaveCount(1);
    $agentB = $result->agents[0];
    $decl = $agentB->toDeclaration();
    expect($decl['key'])->toBe('fixt.products');
    expect($decl['instructions'])->toHaveCount(2);
    expect($decl['tools'])->toHaveCount(1);
    expect($decl['tools'][0]['key'])->toBe('fixt.products.search');
});

it('throws ConfigException-or-similar when scan dir is missing', function () {
    expect(fn () => (new ReflectionScanner())->scan(
        'Vested\\Connect\\Sdk\\Tests\\Fixtures\\OrphanTool',
        __DIR__ . '/../../Fixtures/OrphanTool',
    ))->toThrow(ConfigException::class);
});
