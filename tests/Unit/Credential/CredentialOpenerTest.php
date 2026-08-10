<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Credential\CredentialException;
use Vested\Connect\Sdk\Credential\CredentialOpener;

// Prefixed names: Pest loads every test file into one global namespace, so a
// duplicate top-level function fatals the WHOLE suite at exit 255.
/** @return array<string, mixed> */
function credVectors(): array
{
    $path = __DIR__ . '/../../../../testdata/credential-envelope-vectors.json';
    $raw = file_get_contents($path);
    expect($raw)->not->toBeFalse("fixture missing at $path");

    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * No illuminate/collections in this SDK — collect() does not exist here.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function credNegative(array $fixture, string $name): array
{
    foreach ($fixture['negative'] as $n) {
        if ($n['name'] === $name) {
            return $n;
        }
    }

    throw new RuntimeException("no negative vector named $name");
}

function credFreshKeyPem(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($key === false) {
        throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
    }

    $pem = '';
    openssl_pkey_export($key, $pem);

    return $pem;
}

it('opens every positive vector to its expected plaintext', function () {
    $f = credVectors();
    $opener = new CredentialOpener($f['connector_private_key_pkcs8_pem']);

    foreach ($f['vectors'] as $v) {
        $got = $opener->open($v['envelope'], $v['connector_id'], $v['user_id']);
        expect($got)->toBe($v['plaintext'], "vector {$v['name']}");
    }
});

it('rejects an envelope sealed for a different user', function () {
    $f = credVectors();
    $opener = new CredentialOpener($f['connector_private_key_pkcs8_pem']);
    $neg = credNegative($f, 'aad_identity_mismatch');

    expect(fn () => $opener->open($neg['envelope'], $neg['open_as_connector_id'], $neg['open_as_user_id']))
        ->toThrow(CredentialException::class, 'identity');
});

it('rejects a tampered ciphertext', function () {
    $f = credVectors();
    $opener = new CredentialOpener($f['connector_private_key_pkcs8_pem']);
    $neg = credNegative($f, 'tampered_ciphertext');

    expect(fn () => $opener->open($neg['envelope'], $neg['open_as_connector_id'], $neg['open_as_user_id']))
        ->toThrow(CredentialException::class);
});

it('rejects an unknown algorithm rather than guessing', function () {
    $f = credVectors();
    $opener = new CredentialOpener($f['connector_private_key_pkcs8_pem']);
    $neg = credNegative($f, 'unknown_algorithm');

    expect(fn () => $opener->open($neg['envelope'], $neg['open_as_connector_id'], $neg['open_as_user_id']))
        ->toThrow(CredentialException::class, 'algorithm');
});

it('tries every key in the ring so a rotation overlap still opens', function () {
    $f = credVectors();

    // Newest first: the stale key is tried and fails, the real one succeeds.
    $opener = new CredentialOpener(credFreshKeyPem(), $f['connector_private_key_pkcs8_pem']);
    $v = $f['vectors'][0];

    expect($opener->open($v['envelope'], $v['connector_id'], $v['user_id']))->toBe($v['plaintext']);
});

it('fails when no key in the ring opens the envelope', function () {
    $f = credVectors();
    $opener = new CredentialOpener(credFreshKeyPem());
    $v = $f['vectors'][0];

    expect(fn () => $opener->open($v['envelope'], $v['connector_id'], $v['user_id']))
        ->toThrow(CredentialException::class);
});
