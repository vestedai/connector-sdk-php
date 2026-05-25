<?php

declare(strict_types=1);

namespace Magento\Connector\Agents;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;

#[Agent(key: 'magento.products', name: 'Magento Products', description: 'Catalog search')]
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.3])]
#[Instruction(type: 'system',  position: 0, body: 'You are a Magento product expert.')]
#[Instruction(type: 'task',    position: 1, body: 'Prefer exact SKU matches.')]
#[Instruction(type: 'persona', position: 2, body: 'Concise, professional tone.')]
class ProductsAgent {}
