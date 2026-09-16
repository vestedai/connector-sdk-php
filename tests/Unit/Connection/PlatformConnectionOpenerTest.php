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
        expect($fixture['vectors'] ?? null)->toBeArray()->not->toBeEmpty('the fixture carries no "vectors" list');

        return $fixture['vectors'];
    }
}

if (! function_exists('spcAadParts')) {
    /** @return array{0: string, 1: string, 2: string} platform connector, organization, connection */
    function spcAadParts(string $aad): array
    {
        expect(preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v(\d+)$/', $aad, $m))
            ->toBe(1, "vector aad '{$aad}' is not a platform connection AAD");

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
