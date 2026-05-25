<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Agent;

use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Tool\ToolContext;

it('declares an agent with a model and one instruction', function () {
    $b = new AgentBuilder('magento.products');
    $b->name('Products')
      ->description('search')
      ->withModel('openai', 'gpt-4o', ['temperature' => 0.3])
      ->withInstruction('You are a helper.', type: 'system', position: 0);

    $decl = $b->toDeclaration();
    expect($decl['key'])->toBe('magento.products');
    expect($decl['name'])->toBe('Products');
    expect($decl['model']['provider'])->toBe('openai');
    expect($decl['model']['name'])->toBe('gpt-4o');
    expect($decl['model']['config'])->toBe(['temperature' => 0.3]);
    expect($decl['instructions'])->toHaveCount(1);
    expect($decl['instructions'][0])->toMatchArray([
        'type' => 'system', 'format' => 'markdown', 'body' => 'You are a helper.', 'position' => 0,
    ]);
    expect($decl['tools'])->toBe([]);
});

it('sorts multiple instructions by position', function () {
    $b = new AgentBuilder('x.y');
    $b->withInstruction('B', type: 'task', position: 1)
      ->withInstruction('A', type: 'system', position: 0);
    $decl = $b->toDeclaration();
    expect($decl['instructions'][0]['body'])->toBe('A');
    expect($decl['instructions'][1]['body'])->toBe('B');
});

it('throws on duplicate instruction position', function () {
    $b = new AgentBuilder('x.y');
    $b->withInstruction('A', type: 'system', position: 0)
      ->withInstruction('B', type: 'task',   position: 0);
    expect(fn () => $b->toDeclaration())->toThrow(ConfigException::class, 'duplicate instruction position 0');
});

it('records a tool with closure handler', function () {
    $b = new AgentBuilder('x.y');
    $b->withTool(
        key: 'x.y.search',
        name: 'Search',
        description: 'x',
        inputSchema:  ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
        outputSchema: ['type' => 'object'],
        handler: fn (array $a, ToolContext $c) => ['echo' => $a['q']],
    );
    $decl = $b->toDeclaration();
    expect($decl['tools'])->toHaveCount(1);
    expect($decl['tools'][0]['key'])->toBe('x.y.search');
    expect($decl['tools'][0]['default_deadline_ms'])->toBe(30000);
    expect($decl['tools'][0]['max_result_bytes'])->toBe(1048576);
    expect($b->handlerFor('x.y.search'))->toBeInstanceOf(\Closure::class);
});
