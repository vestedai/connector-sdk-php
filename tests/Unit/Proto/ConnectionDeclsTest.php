<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Generated\Proto\Vested\V1\AgentDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionProfileDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionRequirementDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpResponse;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ResourceDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\TenantConnection;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;

// Asserted against messages rebuilt from serialized bytes, so a field the
// vendored proto lacks (wrong number, wrong type) cannot pass by being set in
// memory only.

it('carries connection profiles and agent requirements across the wire', function () {
    $agent = new AgentDecl(['key' => 'mkt.ga4', 'name' => 'GA4', 'status' => 'active']);
    $agent->setConnectionRequirements([new ConnectionRequirementDecl([
        'profile' => 'google', 'scopes' => ['analytics.readonly'], 'cardinality' => 'one',
    ])]);

    $reg = new Register(['baseline_fingerprint' => 'fp']);
    $reg->setAgents([$agent]);
    $reg->setConnectionProfiles([new ConnectionProfileDecl([
        'key' => 'google', 'title' => 'Google account', 'auth_kind' => 'oauth2',
        'oauth_provider' => 'google', 'scopes' => ['analytics.readonly'],
        'resource' => new ResourceDecl(['discoverable' => true, 'title' => 'Property']),
    ])]);

    $back = new Register();
    $back->mergeFromString($reg->serializeToString());

    expect($back->getConnectionProfiles())->toHaveCount(1)
        ->and($back->getConnectionProfiles()[0]->getOauthProvider())->toBe('google')
        ->and($back->getConnectionProfiles()[0]->getResource()->getDiscoverable())->toBeTrue()
        ->and($back->getAgents()[0]->getConnectionRequirements()[0]->getProfile())->toBe('google');
});

it('carries tenant connections on a tool call and connection ops both ways', function () {
    $req = new ToolCallRequest(['invocation_id' => 'inv-1']);
    $req->setConnections([new TenantConnection([
        'profile' => 'google', 'connection_id' => '12',
        'resource_json' => '{"id":"properties/1"}', 'envelope_json' => '{"v":1}',
    ])]);
    $backReq = new ToolCallRequest();
    $backReq->mergeFromString($req->serializeToString());
    expect($backReq->getConnections()[0]->getConnectionId())->toBe('12');

    $hub = new HubMsg(['connection_op_request' => new ConnectionOpRequest([
        'op_id' => 'op-1', 'op' => 'list_resources', 'organization_id' => '12', 'profile' => 'google',
        'tenant_connection_id' => '3',
    ])]);
    $backHub = new HubMsg();
    $backHub->mergeFromString($hub->serializeToString());
    expect($backHub->getConnectionOpRequest()->getTenantConnectionId())->toBe('3');

    $msg = new ConnectorMsg(['connection_op_response' => new ConnectionOpResponse(['op_id' => 'op-1', 'ok' => true])]);
    $backMsg = new ConnectorMsg();
    $backMsg->mergeFromString($msg->serializeToString());
    expect($backMsg->getConnectionOpResponse()->getOk())->toBeTrue();
});
