<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Fixtures\ExampleAgents;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;

#[Agent(key: 'fixt.products', name: 'Fixture Products', description: 'demo')]
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.3])]
#[Instruction(type: 'system', position: 0, body: 'You are a fixture product agent.')]
#[Instruction(type: 'task',   position: 1, body: 'Prefer SKU matches.')]
class ProductsAgent {}
