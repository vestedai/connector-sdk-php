<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Attribute;

use ReflectionClass;
use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;
use Vested\Connect\Sdk\Attribute\Tool;

#[Agent(key: 'x.products', name: 'Products', description: 'demo')]
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.3])]
#[Instruction(type: 'system', position: 0, body: 'sys')]
#[Instruction(type: 'task',   position: 1, body: 'task')]
class FixtureAgent {}

#[Tool(
    agentKey: 'x.products',
    key: 'x.products.search',
    name: 'Search',
    description: 'do it',
    inputSchemaFile: __DIR__ . '/../../Fixtures/schemas/in.json',
    outputSchemaFile: __DIR__ . '/../../Fixtures/schemas/out.json',
    deadlineMs: 5000,
    maxResultBytes: 65536,
)]
class FixtureTool {}

it('Agent attribute carries key/name/description', function () {
    $attrs = (new ReflectionClass(FixtureAgent::class))->getAttributes(Agent::class);
    expect($attrs)->toHaveCount(1);
    $a = $attrs[0]->newInstance();
    expect($a->key)->toBe('x.products');
    expect($a->name)->toBe('Products');
    expect($a->description)->toBe('demo');
});

it('Multiple Instruction attributes stack on one class', function () {
    $attrs = (new ReflectionClass(FixtureAgent::class))->getAttributes(Instruction::class);
    expect($attrs)->toHaveCount(2);
    $first  = $attrs[0]->newInstance();
    $second = $attrs[1]->newInstance();
    expect($first->position)->toBe(0);
    expect($first->type)->toBe('system');
    expect($second->position)->toBe(1);
    expect($second->type)->toBe('task');
});

it('Model attribute carries provider/name/config', function () {
    $attr = (new ReflectionClass(FixtureAgent::class))->getAttributes(Model::class)[0]->newInstance();
    expect($attr->provider)->toBe('openai');
    expect($attr->name)->toBe('gpt-4o');
    expect($attr->config)->toBe(['temperature' => 0.3]);
});

it('Tool attribute carries full descriptor', function () {
    $attr = (new ReflectionClass(FixtureTool::class))->getAttributes(Tool::class)[0]->newInstance();
    expect($attr->agentKey)->toBe('x.products');
    expect($attr->key)->toBe('x.products.search');
    expect($attr->deadlineMs)->toBe(5000);
    expect($attr->maxResultBytes)->toBe(65536);
    expect($attr->inputSchemaFile)->toContain('in.json');
});
