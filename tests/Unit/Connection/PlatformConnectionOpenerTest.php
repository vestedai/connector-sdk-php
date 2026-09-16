<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Connection\PlatformConnectionOpener;
use Vested\Connect\Sdk\Credential\CredentialException;

if (! function_exists('spcPlatformVectors')) {
    /** @return list<array<string, mixed>> */
    function spcPlatformVectors(): array
    {
        $path = __DIR__.'/../../../testdata/platform-connection-envelope-vectors.json';
        expect(is_file($path))->toBeTrue("fixture missing at {$path} — run vested-ai-sdks/scripts/verify-platform-connection-vectors.sh");

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $vectors = is_array($fixture) ? ($fixture['vectors'] ?? null) : null;
        expect($vectors)->toBeArray()->and($vectors)->not->toBeEmpty('the fixture carries no "vectors" list');

        return $vectors;
    }
}

if (! function_exists('spcPlatformNegatives')) {
    /** @return list<array<string, mixed>> */
    function spcPlatformNegatives(): array
    {
        $path = __DIR__.'/../../../testdata/platform-connection-envelope-vectors.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $negative = is_array($fixture) ? ($fixture['negative'] ?? null) : null;
        expect($negative)->toBeArray()->and($negative)->not->toBeEmpty('the fixture carries no "negative" list');

        return $negative;
    }
}

if (! function_exists('spcAadParts')) {
    /** @return array{0: string, 1: string, 2: string} platform connector, organization, connection */
    function spcAadParts(string $aad): array
    {
        if (preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v(\d+)$/', $aad, $m) !== 1) {
            throw new RuntimeException("vector aad '{$aad}' is not a platform connection AAD");
        }

        return [$m[1], $m[2], $m[3]];
    }
}

it('opens every platform vector to its plaintext', function () {
    foreach (spcPlatformVectors() as $v) {
        [$pc, $org, $connection] = spcAadParts($v['aad']);
        $opener = new PlatformConnectionOpener($v['private_key_pem']);

        expect($opener->open($v['envelope'], $pc, $org, $connection))->toEqual($v['plaintext']);
    }
});

/**
 * The fixture is shared across languages: Laravel's sealer and the per-user
 * opener both run its negative cases. This file used to hand-roll its own
 * instead, so a negative ADDED to the fixture would have been exercised
 * everywhere except here — in the one component whose entire job is to refuse
 * it. Iterating the fixture's own list closes that.
 *
 * The match has no silent default on purpose: a negative whose `expect` this
 * test does not know how to assert fails loudly rather than passing by not
 * being checked.
 */
it('refuses every negative case the shared fixture carries', function () {
    foreach (spcPlatformNegatives() as $n) {
        $opener = new PlatformConnectionOpener($n['private_key_pem']);
        $open = fn () => $opener->open(
            $n['envelope'],
            (string) $n['open_as_platform_connector_id'],
            (string) $n['open_as_organization_id'],
            (string) $n['open_as_connection_id'],
        );

        $expected = match ($n['expect']) {
            'identity_mismatch' => 'identity mismatch',
            'decrypt_failed'    => 'failed to decrypt',
            'unknown_algorithm' => 'unsupported credential envelope algorithm',
            default             => throw new RuntimeException(
                "negative vector '{$n['name']}' expects '{$n['expect']}', which this test does "
                . 'not know how to assert — teach it here rather than leaving the case unrun'
            ),
        };

        expect($open)->toThrow(CredentialException::class, $expected);
    }
});

it('refuses an envelope replayed into another tenant, connection or connector', function (int $which) {
    $v = spcPlatformVectors()[0];
    [$pc, $org, $connection] = spcAadParts($v['aad']);
    $ids = [$pc, $org, $connection];
    $ids[$which] = (string) ((int) $ids[$which] + 1);

    (new PlatformConnectionOpener($v['private_key_pem']))->open($v['envelope'], ...$ids);
})->with([0, 1, 2])->throws(CredentialException::class, 'identity mismatch');

it('refuses an altered aad even when the invocation claims the altered identity', function () {
    $v = spcPlatformVectors()[0];
    [$pc, $org, $connection] = spcAadParts($v['aad']);
    $forgedOrg = (string) ((int) $org + 1);

    $envelope = $v['envelope'];
    $envelope['aad'] = PlatformConnectionOpener::aad($pc, $forgedOrg, $connection, (int) ($envelope['v'] ?? 1));

    // The label check passes (it matches the forged claim); GCM authentication
    // over the ORIGINAL aad does not.
    (new PlatformConnectionOpener($v['private_key_pem']))->open($envelope, $pc, $forgedOrg, $connection);
})->throws(CredentialException::class, 'failed to decrypt');

it('refuses to open without the full identity', function () {
    $v = spcPlatformVectors()[0];

    (new PlatformConnectionOpener($v['private_key_pem']))->open($v['envelope'], '', '12', '3');
})->throws(CredentialException::class);

it('never opens a per-user envelope as a platform connection', function () {
    $f = json_decode((string) file_get_contents(__DIR__.'/../../../testdata/credential-envelope-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    $userVector = $f['vectors'][0];

    (new PlatformConnectionOpener($f['connector_private_key_pkcs8_pem']))->open($userVector['envelope'], '42', '1', '1');
})->throws(CredentialException::class, 'identity mismatch');
