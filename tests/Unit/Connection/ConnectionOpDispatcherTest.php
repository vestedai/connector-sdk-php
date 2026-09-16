<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Connection;

use Vested\Connect\Sdk\Attribute\ConnectionProfile;
use Vested\Connect\Sdk\Connection\ConnectionOpContext;
use Vested\Connect\Sdk\Connection\ConnectionOpDispatcher;
use Vested\Connect\Sdk\Connection\ConnectionProfileHandler;
use Vested\Connect\Sdk\Connection\ConnectionResource;
use Vested\Connect\Sdk\Connection\PlatformConnectionOpener;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpResponse;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionResource as ConnectionResourceDecl;
use Vested\Connect\Sdk\Tests\Unit\Support\Decoded;

/** @return array{0: ConnectionOpDispatcher, 1: RecordingGoogleProfileBase, 2: array<string, mixed>, 3: string, 4: string, 5: string} */
function opFixture(): array
{
    $f = json_decode((string) file_get_contents(__DIR__.'/../../../testdata/platform-connection-envelope-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $v = $f['vectors'][0];
    // An unguarded preg_match leaves $m[1..3] undefined when the fixture's aad
    // shape changes, and the fixture then silently drives the whole file with
    // empty identities — every tenancy assertion passing against ''.
    if (preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v\d+$/', (string) $v['aad'], $m) !== 1) {
        throw new \RuntimeException("vector aad '{$v['aad']}' is not a platform connection AAD");
    }

    $handler = new #[ConnectionProfile(key: 'google', title: 'Google', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])]
        class extends RecordingGoogleProfileBase {};
    $app = ConnectorApp::create()->withConnectionProfile($handler, [$v['private_key_pem']]);

    return [new ConnectionOpDispatcher(new PlatformConnectionOpener($v['private_key_pem']), $app, fn (): string => $m[1]), $handler, $v, $m[1], $m[2], $m[3]];
}

/** @return list<ConnectionResourceDecl> */
function opResources(ConnectionOpResponse $resp): array
{
    return Decoded::repeated($resp->getResources(), ConnectionResourceDecl::class, 'ConnectionOpResponse.resources');
}

/** Attributes are not inherited, so the fixture declares its own on an anonymous subclass. */
abstract class RecordingGoogleProfileBase implements ConnectionProfileHandler
{
    public ?ConnectionOpContext $ctx = null;

    /** @var array<string, mixed>|null */
    public ?array $credential = null;

    public bool $explode = false;

    public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation
    {
        [$this->ctx, $this->credential] = [$ctx, $credential];
        if ($this->explode) {
            throw new \RuntimeException('secret-bearing provider error body');
        }

        return CredentialValidation::ok(['account' => 'marketing@acme.com']);
    }

    public function listResources(ConnectionOpContext $ctx, array $credential): array
    {
        [$this->ctx, $this->credential] = [$ctx, $credential];

        return [new ConnectionResource('properties/1', 'Main', ['currency' => 'SAR'])];
    }
}

/** @param array<string, mixed> $envelope */
function opRequest(string $op, string $org, string $connection, array $envelope, string $profile = 'google'): ConnectionOpRequest
{
    return new ConnectionOpRequest([
        'op_id' => 'op-1', 'op' => $op, 'organization_id' => $org, 'profile' => $profile,
        'tenant_connection_id' => $connection, 'envelope_json' => (string) json_encode($envelope), 'deadline_ms' => 1000,
    ]);
}

it('lists resources with the opened credential and the calling tenant', function () {
    [$dispatcher, $handler, $v, , $org, $conn] = opFixture();

    $resp = $dispatcher->dispatch(opRequest('list_resources', $org, $conn, $v['envelope']));

    $resources = opResources($resp);
    $meta = Decoded::structFields($resources[0]->getMeta(), 'resources[0].meta');
    // The handler records the context it was called with; if dispatch never
    // reached it, $ctx stays null and the tenancy assertions below would
    // otherwise be skipped rather than failed.
    $ctx = Decoded::present($handler->ctx, 'the context the handler was called with');

    expect($resp->getOpId())->toBe('op-1')
        ->and($resp->getOk())->toBeTrue()
        ->and($resources)->toHaveCount(1)
        ->and($resources[0]->getId())->toBe('properties/1')
        ->and($meta['currency']->getStringValue())->toBe('SAR')
        ->and($handler->credential)->toEqual($v['plaintext'])
        ->and($ctx->organizationId)->toBe($org)
        ->and($ctx->tenantConnectionId)->toBe($conn);
});

/**
 * ConnectionOpRequest.deadline_ms was carried on the wire and then dropped:
 * a handler calling a third-party API had nothing to bound its own client
 * with, and would keep waiting after the hub had already given up on the op.
 * 2500, not opRequest()'s default, so this cannot pass on a constant.
 */
it('carries the request deadline so a handler can bound its own client', function () {
    [$dispatcher, $handler, $v, , $org, $conn] = opFixture();

    $req = opRequest('validate', $org, $conn, $v['envelope']);
    $req->setDeadlineMs(2500);
    $dispatcher->dispatch($req);

    expect(Decoded::present($handler->ctx, 'the context the handler was called with')->deadlineMs)->toBe(2500);
});

it('validates and returns display facts', function () {
    [$dispatcher, , $v, , $org, $conn] = opFixture();

    $resp = $dispatcher->dispatch(opRequest('validate', $org, $conn, $v['envelope']));

    $display = Decoded::structFields($resp->getDisplay(), 'ConnectionOpResponse.display');

    expect($resp->getOk())->toBeTrue()
        ->and($display['account']->getStringValue())->toBe('marketing@acme.com');
});

it('answers every failure with a fixed sentence and never the cause', function (string $case, string $sentence) {
    [$dispatcher, $handler, $v, , $org, $conn] = opFixture();

    $req = match ($case) {
        'unknown profile' => opRequest('validate', $org, $conn, $v['envelope'], 'meta'),
        'other tenant'    => opRequest('validate', (string) ((int) $org + 1), $conn, $v['envelope']),
        'handler threw'   => opRequest('validate', $org, $conn, $v['envelope']),
        'unknown op'      => opRequest('revoke', $org, $conn, $v['envelope']),
        default           => throw new \InvalidArgumentException("no request built for case '{$case}'"),
    };
    if ($case === 'handler threw') {
        $handler->explode = true;
    }

    $resp = $dispatcher->dispatch($req);

    expect($resp->getOk())->toBeFalse()
        ->and($resp->getError())->toBe($sentence)
        ->and($resp->getError())->not->toContain('secret-bearing');
})->with([
    ['unknown profile', 'This integration does not provide that connection.'],
    ['other tenant', 'This integration could not read the connection. Please reconnect it.'],
    ['handler threw', 'The integration could not check this connection right now.'],
    ['unknown op', 'This integration does not support that connection operation.'],
]);

/**
 * Ruling R (dispatch override 1): toStruct() must type-dispatch, not
 * stringify. A blind (string) cast turns true into "1", and turns both false
 * and null into "" — indistinguishable in the tenant's admin UI, and silent.
 * Falsified by hand against the brief's stringifying form (see task-5-report.md).
 */
it('puts a bool, a number and a null into meta as their own typed Value, not a string', function () {
    [, , $v, $pc, $org, $conn] = opFixture();
    // opFixture()'s handler always returns ['currency' => 'SAR']; build a
    // second handler on the same profile key to control meta directly.
    $handler = new #[ConnectionProfile(key: 'google', title: 'Google', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])]
        class implements ConnectionProfileHandler {
            public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation
            {
                return CredentialValidation::ok();
            }

            public function listResources(ConnectionOpContext $ctx, array $credential): array
            {
                return [new ConnectionResource('properties/1', 'Main', [
                    'currency' => 'SAR', 'active' => true, 'archived' => false, 'count' => 3, 'note' => null,
                ])];
            }
        };
    $app = ConnectorApp::create()->withConnectionProfile($handler, [$v['private_key_pem']]);
    $dispatcher = new ConnectionOpDispatcher(new PlatformConnectionOpener($v['private_key_pem']), $app, fn (): string => $pc);

    $resp = $dispatcher->dispatch(opRequest('list_resources', $org, $conn, $v['envelope']));

    $fields = Decoded::structFields(opResources($resp)[0]->getMeta(), 'resources[0].meta');
    expect($fields['currency']->hasStringValue())->toBeTrue()
        ->and($fields['currency']->getStringValue())->toBe('SAR')
        ->and($fields['active']->hasBoolValue())->toBeTrue()
        ->and($fields['active']->getBoolValue())->toBeTrue()
        ->and($fields['archived']->hasBoolValue())->toBeTrue()
        ->and($fields['archived']->getBoolValue())->toBeFalse()
        ->and($fields['count']->hasNumberValue())->toBeTrue()
        ->and($fields['count']->getNumberValue())->toBe(3.0)
        ->and($fields['note']->hasNullValue())->toBeTrue();
});

/**
 * Review fix round 1 on task 5: an array in meta/display is not scalar, null,
 * or Stringable — it violates ConnectionResource's documented
 * array<string, scalar|null> contract, which PHP does not enforce. Before the
 * guard, (string) $array emits an E_WARNING (not a Throwable) and evaluates
 * to the literal string "Array", which slipped past the dispatcher's
 * catch (Throwable) and reached the response as if it were a real value.
 * Falsified by hand: removing the guard turns this test red with
 * getOk() === true and the meta field holding the string "Array" instead of
 * the fixed sentence (see task-5-report.md, "Fix round 1").
 */
it('refuses a non-scalar meta value with the same fixed sentence as a handler exception', function () {
    [, , $v, $pc, $org, $conn] = opFixture();
    $handler = new #[ConnectionProfile(key: 'google', title: 'Google', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])]
        class implements ConnectionProfileHandler {
            /**
             * Declared `mixed` because that is what it really is: a fact a
             * real handler decoded from a third-party payload, which the
             * static type cannot vouch for. Typing it as the array it holds
             * would move the failure to the call site below and this test
             * would no longer reach the runtime guard it exists to pin.
             */
            public mixed $fromProvider = ['scopes' => ['a', 'b']];

            public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation
            {
                return CredentialValidation::ok();
            }

            public function listResources(ConnectionOpContext $ctx, array $credential): array
            {
                // Violates ConnectionResource's documented
                // array<string, scalar|Stringable|null> contract on purpose —
                // this is what the guard exists to catch.
                return [new ConnectionResource('properties/1', 'Main', $this->fromProvider)];
            }
        };
    $app = ConnectorApp::create()->withConnectionProfile($handler, [$v['private_key_pem']]);
    $dispatcher = new ConnectionOpDispatcher(new PlatformConnectionOpener($v['private_key_pem']), $app, fn (): string => $pc);

    $resp = $dispatcher->dispatch(opRequest('list_resources', $org, $conn, $v['envelope']));

    expect($resp->getOk())->toBeFalse()
        ->and($resp->getError())->toBe('The integration could not check this connection right now.')
        ->and($resp->getError())->not->toContain('Array')
        ->and($resp->getError())->not->toContain('scopes');
});

it('still stringifies a Stringable meta value, and every scalar keeps passing', function () {
    [, , $v, $pc, $org, $conn] = opFixture();
    $handler = new #[ConnectionProfile(key: 'google', title: 'Google', authKind: 'oauth2', oauthProvider: 'google', scopes: ['a'])]
        class implements ConnectionProfileHandler {
            public ?\Stringable $note = null;

            public function validate(ConnectionOpContext $ctx, array $credential): CredentialValidation
            {
                return CredentialValidation::ok();
            }

            public function listResources(ConnectionOpContext $ctx, array $credential): array
            {
                return [new ConnectionResource('properties/1', 'Main', [
                    'label' => $this->note, 'currency' => 'SAR', 'active' => true, 'count' => 3, 'gap' => null,
                ])];
            }
        };
    $handler->note = new class implements \Stringable {
        public function __toString(): string
        {
            return 'region: eu-west';
        }
    };
    $app = ConnectorApp::create()->withConnectionProfile($handler, [$v['private_key_pem']]);
    $dispatcher = new ConnectionOpDispatcher(new PlatformConnectionOpener($v['private_key_pem']), $app, fn (): string => $pc);

    $resp = $dispatcher->dispatch(opRequest('list_resources', $org, $conn, $v['envelope']));

    $fields = Decoded::structFields(opResources($resp)[0]->getMeta(), 'resources[0].meta');
    expect($resp->getOk())->toBeTrue()
        ->and($fields['label']->hasStringValue())->toBeTrue()
        ->and($fields['label']->getStringValue())->toBe('region: eu-west')
        ->and($fields['currency']->getStringValue())->toBe('SAR')
        ->and($fields['active']->getBoolValue())->toBeTrue()
        ->and($fields['count']->getNumberValue())->toBe(3.0)
        ->and($fields['gap']->hasNullValue())->toBeTrue();
});
