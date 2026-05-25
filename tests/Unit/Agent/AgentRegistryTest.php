<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Agent;

use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Agent\AgentRegistry;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolRegistry;

it('aggregates agent declarations and tool handlers across multiple agents', function () {
    $a = (new AgentBuilder('x.a'))->withTool(
        key: 'x.a.t', name: 'T', description: '',
        inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
        handler: fn (array $args, ToolContext $c) => ['ok' => true],
    );
    $b = (new AgentBuilder('x.b'))->withTool(
        key: 'x.b.u', name: 'U', description: '',
        inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
        handler: fn (array $args, ToolContext $c) => ['ok' => true],
    );

    $registry = new AgentRegistry([$a, $b]);
    expect($registry->declarations())->toHaveCount(2);
    expect($registry->declarations()[0]['key'])->toBe('x.a');

    $tools = ToolRegistry::fromAgents([$a, $b]);
    expect($tools->has('x.a.t'))->toBeTrue();
    expect($tools->has('x.b.u'))->toBeTrue();
    expect($tools->has('nope'))->toBeFalse();
});

it('computes a stable fingerprint from sorted declarations', function () {
    $a = new AgentBuilder('x.a');
    $a->withInstruction('hi', position: 0);
    $r1 = new AgentRegistry([$a]);

    $b = new AgentBuilder('x.a');
    $b->withInstruction('hi', position: 0);
    $r2 = new AgentRegistry([$b]);

    expect($r1->fingerprint())->toBe($r2->fingerprint());
    expect(strlen($r1->fingerprint()))->toBe(64);
});

it('changes fingerprint when a body changes', function () {
    $a = (new AgentBuilder('x.a'))->withInstruction('hi', position: 0);
    $b = (new AgentBuilder('x.a'))->withInstruction('bye', position: 0);
    expect((new AgentRegistry([$a]))->fingerprint())
        ->not->toBe((new AgentRegistry([$b]))->fingerprint());
});
