<?php

declare(strict_types=1);

namespace Acme\Commerce\Operations;

use Acme\Commerce\Magento\RestClient;
use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolHandler;

#[Tool(
    agentKey:       'acme_commerce.operations',
    key:            'acme_commerce.operations.order_status',
    name:           'Order status',
    description:    'Current status, line items, and shipping tracks for an order.',
    deadlineMs:     30000,
    maxResultBytes: 32768,
)]
final class OrderStatusHandler implements ToolHandler
{
    public function __construct(private readonly RestClient $rest) {}

    /** @param array<string, mixed> $args */
    public function handle(array $args, ToolContext $ctx): array
    {
        $incrementId = (string) ($args['order_id']
            ?? throw new \InvalidArgumentException('order_id required'));

        $resp = $this->rest->get('/rest/V1/orders', [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'increment_id',
            'searchCriteria[filterGroups][0][filters][0][value]'         => $incrementId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            'searchCriteria[pageSize]'                                   => 1,
        ]);

        $orders = (array) ($resp['items'] ?? []);
        if ($orders === []) {
            return ['found' => false];
        }

        /** @var array<string, mixed> $order */
        $order = $orders[0];
        $items = [];
        foreach ((array) ($order['items'] ?? []) as $it) {
            if (!empty($it['parent_item_id'])) {
                continue; // skip configurable children
            }
            $items[] = [
                'sku'         => (string) $it['sku'],
                'name'        => (string) ($it['name'] ?? ''),
                'qty_ordered' => (float) ($it['qty_ordered'] ?? 0),
            ];
        }

        return [
            'found'        => true,
            'order_number' => (string) $order['increment_id'],
            'status'       => (string) $order['status'],
            'grand_total'  => (float) $order['grand_total'],
            'items'        => $items,
        ];
    }
}
