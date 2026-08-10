<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Credential\CredentialContext;
use Vested\Connect\Sdk\Credential\CredentialOpDispatcher;
use Vested\Connect\Sdk\Credential\CredentialOpener;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Credential\UserCredentialHandler;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\CredentialOpRequest;

// Prefixed names: Pest loads every test file into one global namespace, so a
// duplicate top-level function fatals the WHOLE suite at exit 255.
/** @return array<string, mixed> */
function copdVectors(): array
{
    $raw = file_get_contents(__DIR__.'/../../../testdata/credential-envelope-vectors.json');

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A handler that records what it saw, so the test can assert the SDK handed
 * over plaintext rather than an envelope.
 */
final class CopdSpyHandler implements UserCredentialHandler
{
    /** @var array<string, string>|null */
    public ?array $sawCredential = null;

    public ?CredentialContext $sawCtx = null;

    public int $revokeCalls = 0;

    public function __construct(private readonly ?CredentialValidation $verdict = null) {}

    public function validate(CredentialContext $ctx, array $credential): CredentialValidation
    {
        $this->sawCredential = $credential;
        $this->sawCtx = $ctx;

        return $this->verdict ?? CredentialValidation::ok(['account' => 'j.smith@erp']);
    }

    public function revoke(CredentialContext $ctx, array $credential): void
    {
        $this->revokeCalls++;
        $this->sawCredential = $credential;
    }
}

final class CopdThrowingHandler implements UserCredentialHandler
{
    public function validate(CredentialContext $ctx, array $credential): CredentialValidation
    {
        throw new RuntimeException('ERP host db-prod-07.internal refused: connection reset');
    }

    public function revoke(CredentialContext $ctx, array $credential): void {}
}

/** @param array<string, mixed> $envelope */
function copdRequest(array $envelope, string $userId, string $op = 'validate'): CredentialOpRequest
{
    return new CredentialOpRequest([
        'op_id'         => 'op-1',
        'op'            => $op,
        'user_id'       => $userId,
        'user_email'    => 'j.smith@example.com',
        'envelope_json' => (string) json_encode($envelope),
        'deadline_ms'   => 5000,
    ]);
}

it('opens the envelope and hands the handler plaintext', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];
    $handler = new CopdSpyHandler;

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        $handler,
        fn (): string => $v['connector_id'],
    );

    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], $v['user_id']));

    expect($resp->getOk())->toBeTrue()
        ->and($handler->sawCredential)->toBe($v['plaintext'])
        ->and($handler->sawCtx?->userId)->toBe($v['user_id'])
        ->and($resp->getDisplay()?->getFields()['account']->getStringValue())->toBe('j.smith@erp');
});

it('surfaces a handler refusal as ok=false with its message', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        new CopdSpyHandler(CredentialValidation::failed('ERP rejected those credentials.')),
        fn (): string => $v['connector_id'],
    );

    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], $v['user_id']));

    expect($resp->getOk())->toBeFalse()
        ->and($resp->getError())->toBe('ERP rejected those credentials.');
});

it('refuses an envelope sealed for a different user without calling the handler', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];
    $handler = new CopdSpyHandler;

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        $handler,
        fn (): string => $v['connector_id'],
    );

    // Same envelope, different caller — the AAD no longer matches.
    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], '999999'));

    expect($resp->getOk())->toBeFalse()
        ->and($handler->sawCredential)->toBeNull('the handler must never see a mismatched credential');
});

it('never leaks handler exception text to the user', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        new CopdThrowingHandler,
        fn (): string => $v['connector_id'],
    );

    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], $v['user_id']));

    expect($resp->getOk())->toBeFalse()
        ->and($resp->getError())->not->toContain('db-prod-07.internal')
        ->and($resp->getError())->not->toContain('connection reset');
});

it('runs revoke when asked', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];
    $handler = new CopdSpyHandler;

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        $handler,
        fn (): string => $v['connector_id'],
    );

    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], $v['user_id'], 'revoke'));

    expect($resp->getOk())->toBeTrue()
        ->and($handler->revokeCalls)->toBe(1);
});

it('answers ok=false rather than staying silent when no handler is registered', function () {
    $f = copdVectors();
    $v = $f['vectors'][0];

    $dispatcher = new CredentialOpDispatcher(
        new CredentialOpener($f['connector_private_key_pkcs8_pem']),
        null,
        fn (): string => $v['connector_id'],
    );

    // Silence would make the platform wait out its whole deadline.
    $resp = $dispatcher->dispatch(copdRequest($v['envelope'], $v['user_id']));

    expect($resp->getOk())->toBeFalse()
        ->and($resp->getOpId())->toBe('op-1');
});
