<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

/**
 * Closes the coroutine gap Task 4 left: every other test exercises
 * Tool\ToolDispatcher directly (see tests/Unit/Connection/ToolCallConnectionsTest.php).
 * Nothing drove a Swoole\CoroutineDispatcher with a real PlatformConnectionOpener
 * through actual Swoole coroutines — exactly where a cached organization id
 * would hide, since CoroutineDispatcher wraps ONE ToolDispatcher instance
 * shared across every coroutine it spawns.
 *
 * This dispatches two ToolCallRequests from two DIFFERENT tenants — each
 * carrying its own envelope, sealed to its own organization id — before
 * either coroutine has had a chance to run, then proves each opened only its
 * own envelope. If organizationId were ever memoized on the shared
 * ToolDispatcher (or the Connection it builds) instead of read fresh off each
 * request, tenant B's call would either decrypt to tenant A's credential or
 * fail identity verification — see the falsification in task-5-report.md.
 */

use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Connection\PlatformConnectionOpener;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\TenantConnection;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Observability\Tracing;
use Vested\Connect\Sdk\Swoole\CoroutineDispatcher;
use Vested\Connect\Sdk\Swoole\OutboundChannel;
use Vested\Connect\Sdk\Tool\ToolContext;
use Vested\Connect\Sdk\Tool\ToolRegistry;

/**
 * Seals a payload the same way EnvelopeCipher::open() expects to read one:
 * ECDH-P256 -> HKDF-SHA256(info='vested-connector-credential-v1', salt=32
 * zero bytes) -> AES-256-GCM. There is no PHP-side sealer in this SDK (the
 * Laravel platform seals; `php artisan platform-connections:envelope-vectors`
 * produced testdata/platform-connection-envelope-vectors.json), and the fixed
 * fixture only carries one organization per platform connector id, so a
 * second tenant's envelope is sealed here, to the SAME public key the fixture
 * vector uses (every vector in that file shares one EC key pair).
 *
 * @param array<string, mixed> $plaintext
 * @return array<string, mixed>
 */
function sealPlatformEnvelope(string $publicKeyPem, string $aad, array $plaintext): array
{
    $recipient = openssl_pkey_get_public($publicKeyPem);
    $ephemeral = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $z = openssl_pkey_derive($recipient, $ephemeral, 32);
    if ($z === false) {
        throw new \RuntimeException('ECDH derive failed building the test fixture');
    }
    $key = hash_hkdf('sha256', $z, 32, 'vested-connector-credential-v1', str_repeat("\0", 32));
    $iv = random_bytes(12);
    $pt = json_encode($plaintext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ct = openssl_encrypt((string) $pt, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($ct === false) {
        throw new \RuntimeException('AES-GCM seal failed building the test fixture');
    }
    $details = openssl_pkey_get_details($ephemeral);
    $epkDer = base64_decode(preg_replace('/-----[^-]+-----|\r|\n/', '', $details['key']));

    return [
        'v' => 1, 'alg' => 'ECDH-P256+HKDF-SHA256+A256GCM', 'kid' => 'sha256:test-fixture',
        'epk' => base64_encode($epkDer), 'iv' => base64_encode($iv),
        'ct' => base64_encode($ct.$tag), 'aad' => $aad,
    ];
}

it('opens only the calling tenant\'s envelope for each of two concurrent coroutine dispatches', function () {
    \Swoole\Coroutine\run(function () {
        $f = json_decode((string) file_get_contents(__DIR__.'/../../../testdata/platform-connection-envelope-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $v = $f['vectors'][0];
        preg_match('/^platform:(\d+)\|org:(\d+)\|connection:(\d+)\|v\d+$/', $v['aad'], $m);
        [, $pc, $orgA, $connA] = $m;
        $plaintextA = $v['plaintext'];
        $envelopeA = $v['envelope'];

        $orgB = (string) ((int) $orgA + 987);
        $connB = (string) ((int) $connA + 1);
        $plaintextB = ['access_token' => 'org-b-only-token', 'token_type' => 'Bearer', 'expires_at' => '2027-01-01T00:00:00Z'];
        $envelopeB = sealPlatformEnvelope($v['public_key_pem'], PlatformConnectionOpener::aad($pc, $orgB, $connB, 1), $plaintextB);

        $agent = (new AgentBuilder('mkt.ga4'))->withModel('openai', 'gpt-4o')->withInstruction('x')
            ->withTool(
                key: 'mkt.ga4.report', name: 'R', description: '',
                inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                handler: fn (array $a, ToolContext $ctx) => ['credential' => $ctx->connections()->one('google')->credential()],
            );

        $ch = new OutboundChannel();
        $disp = new CoroutineDispatcher(
            registry: ToolRegistry::fromAgents(['mkt.ga4' => $agent]),
            toolMeta: ['mkt.ga4.report' => ['input_schema' => ['type' => 'object'], 'output_schema' => ['type' => 'object']]],
            outbound: $ch,
            logger: new NullLogger(),
            tracing: new Tracing(null),
            connectorId: fn (): string => $pc,
            connectionOpener: new PlatformConnectionOpener($v['private_key_pem']),
        );

        $reqA = new ToolCallRequest([
            'invocation_id' => 'tenant-a', 'agent_key' => 'mkt.ga4', 'tool_key' => 'mkt.ga4.report',
            'args_json' => '{}', 'organization_id' => $orgA,
        ]);
        $reqA->setConnections([new TenantConnection([
            'profile' => 'google', 'label' => '', 'connection_id' => $connA,
            'resource_json' => '{}', 'envelope_json' => (string) json_encode($envelopeA),
        ])]);

        $reqB = new ToolCallRequest([
            'invocation_id' => 'tenant-b', 'agent_key' => 'mkt.ga4', 'tool_key' => 'mkt.ga4.report',
            'args_json' => '{}', 'organization_id' => $orgB,
        ]);
        $reqB->setConnections([new TenantConnection([
            'profile' => 'google', 'label' => '', 'connection_id' => $connB,
            'resource_json' => '{}', 'envelope_json' => (string) json_encode($envelopeB),
        ])]);

        // Dispatch BOTH before either coroutine has run, so a shared/cached
        // org id on the dispatcher would leak from one call into the other.
        $disp->dispatch($reqA);
        $disp->dispatch($reqB);

        $responses = [];
        for ($i = 0; $i < 2; $i++) {
            $out = $ch->popOrNull(timeoutSeconds: 2.0);
            expect($out)->not->toBeNull();
            $tcr = $out->getToolCallResponse();
            $responses[$tcr->getInvocationId()] = $tcr;
        }

        expect($responses['tenant-a']->getError())->toBe('')
            ->and(json_decode($responses['tenant-a']->getResultJson(), true)['credential'])->toEqual($plaintextA)
            ->and($responses['tenant-b']->getError())->toBe('')
            ->and(json_decode($responses['tenant-b']->getResultJson(), true)['credential'])->toEqual($plaintextB);
    });
})->skip(! extension_loaded('swoole'), 'Swoole not installed');
