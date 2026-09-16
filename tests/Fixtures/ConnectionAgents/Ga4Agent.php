<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Fixtures\ConnectionAgents;

use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;
use Vested\Connect\Sdk\Attribute\RequiresConnection;

#[Agent(key: 'mkt.ga4', name: 'GA4', description: 'Website analytics')]
#[Model(provider: 'openai', name: 'gpt-4o')]
#[Instruction(type: 'system', position: 0, body: 'You analyse GA4.')]
#[RequiresConnection(profile: 'google', scopes: ['analytics.readonly'])]
class Ga4Agent {}
