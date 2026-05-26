<?php

declare(strict_types=1);

namespace Acme\Commerce\Insights;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;

#[Agent(
    key:         'acme_commerce.insights',
    name:        'Commerce Business Insights',
    description: 'Sales analytics and inventory health for managers and analysts.',
)]
#[Model(provider: 'openai', name: 'gpt-4o', config: ['temperature' => 0.1])]
#[Instruction(type: 'system', position: 0, body: <<<'PROMPT'
You are a business analytics assistant for an e-commerce store. Produce
sales reports and inventory health checks. Always cite the time range and
store context for every metric you present. When the user does not specify
a date range, default to the last 30 days for sales and current state for
inventory.
PROMPT)]
#[Instruction(type: 'task', position: 1, body: <<<'PROMPT'
For "what's selling" questions use bestsellers. For inventory health use
inventory_summary. After presenting numbers, suggest one concrete follow-up
action when relevant (e.g., "5 SKUs are below safety stock — want the list?").
PROMPT)]
final class InsightsAgent
{
    // Marker class. The SDK #[Agent] scanner requires no methods.
}
