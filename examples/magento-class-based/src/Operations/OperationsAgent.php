<?php

declare(strict_types=1);

namespace Acme\Commerce\Operations;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;

#[Agent(
    key:         'acme_commerce.operations',
    name:        'Commerce Operations Assistant',
    description: 'Day-to-day order and catalog queries for internal staff.',
)]
#[Model(provider: 'openai', name: 'gpt-4o-mini', config: ['temperature' => 0.2])]
#[Instruction(type: 'system', position: 0, body: <<<'PROMPT'
You are an operations assistant for an e-commerce store built on Magento 2.
Help staff look up orders, check inventory, and find products. Be concise
and direct. When a SKU is known, always include it in your response.
PROMPT)]
#[Instruction(type: 'task', position: 1, body: <<<'PROMPT'
For order questions, use order_status with the increment_id (e.g. "100012345").
If a tool returns found=false, tell the user plainly rather than guessing.
PROMPT)]
final class OperationsAgent
{
    // Marker class. The SDK #[Agent] scanner requires no methods.
}
