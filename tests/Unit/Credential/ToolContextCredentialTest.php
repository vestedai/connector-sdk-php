<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Credential\CredentialException;
use Vested\Connect\Sdk\Credential\CredentialOpener;
use Vested\Connect\Sdk\Credential\CredentialResolver;
use Vested\Connect\Sdk\Credential\CredentialUnavailable;
use Vested\Connect\Sdk\Tool\ToolContext;

// Prefixed names: Pest loads every test file into one global namespace, so a
// duplicate top-level function fatals the WHOLE suite at exit 255.
/** @return array<string, mixed> */
function tccVectors(): array
{
    $raw = file_get_contents(__DIR__.'/../../../testdata/credential-envelope-vectors.json');

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

function tccContext(?CredentialResolver $resolver): ToolContext
{
    return new ToolContext(
        invocationId: 'inv-1',
        organizationId: '1',
        userId: '1337',
        userEmail: 'j.smith@example.com',
        conversationId: '',
        agentKey: 'erp.agent',
        toolKey: 'erp.lookup',
        deadlineMs: 5000,
        logger: new Psr\Log\NullLogger,
        invokedAt: new DateTimeImmutable,
        credentials: $resolver,
    );
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $envelope
 */
function tccResolver(array $fixture, array $envelope, string $connectorId, string $userId): CredentialResolver
{
    return new CredentialResolver(
        opener: new CredentialOpener($fixture['connector_private_key_pkcs8_pem']),
        envelopeJson: (string) json_encode($envelope),
        connectorId: $connectorId,
        userId: $userId,
    );
}

it('hands a tool the decrypted credential', function () {
    $f = tccVectors();
    $v = $f['vectors'][0];

    $ctx = tccContext(tccResolver($f, $v['envelope'], $v['connector_id'], $v['user_id']));

    expect($ctx->hasCredential())->toBeTrue()
        ->and($ctx->credential())->toBe($v['plaintext']);
});

it('memoizes, so a tool reading it twice pays for one key agreement', function () {
    $f = tccVectors();
    $v = $f['vectors'][0];

    $ctx = tccContext(tccResolver($f, $v['envelope'], $v['connector_id'], $v['user_id']));

    expect($ctx->credential())->toBe($ctx->credential());
});

it('refuses an envelope sealed for a different user', function () {
    $f = tccVectors();
    $v = $f['vectors'][0];

    // Same envelope, different caller — the AAD no longer matches. The check
    // lives in CredentialOpener, on the only path a tool author can reach.
    $ctx = tccContext(tccResolver($f, $v['envelope'], $v['connector_id'], '999999'));

    expect(fn () => $ctx->credential())->toThrow(CredentialException::class, 'identity');
});

it('reports no credential rather than throwing, so a tool can branch', function () {
    $ctx = tccContext(new CredentialResolver(
        opener: null, envelopeJson: '', connectorId: '42', userId: '1337',
    ));

    expect($ctx->hasCredential())->toBeFalse();
});

it('throws a named error when a tool asks for a credential that was never sent', function () {
    $ctx = tccContext(null);

    expect($ctx->hasCredential())->toBeFalse()
        ->and(fn () => $ctx->credential())->toThrow(CredentialUnavailable::class);
});
