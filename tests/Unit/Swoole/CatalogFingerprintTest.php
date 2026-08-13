<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Swoole;

// ---------------------------------------------------------------------------
// The BOUND on the catalog fingerprint, which is the only thing standing
// between a cold database and a connector whose entire tool surface is offline.
//
// Register is sent inside the hub's 30s idle window (the timer starts at
// HelloAck and the heartbeat only starts at RegisterAck), so an unbounded
// catalog scan means GoAway{idle} before Register is ever sent — forever, with
// "idle" as the only log line. These live under tests/Unit/Swoole because they
// need a real scheduler: the bound is a coroutine race, so asserting it outside
// Coroutine\run() would assert the unbounded fallback instead.
// ---------------------------------------------------------------------------

use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Hub\StreamHandler;
use Vested\Connect\Sdk\Schema\CanonicalSchema;
use Vested\Connect\Sdk\Schema\CatalogFingerprint;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;
use Vested\Connect\Sdk\Tool\ToolContext;

final class FpRecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function joined(): string
    {
        return implode("\n", $this->messages);
    }
}

/** @param \Closure(): string $fingerprint */
function fpProvider(\Closure $fingerprint): RelationalSchemaProvider
{
    return new
    #[RelationalSource(engine: 'mysql', describeTool: 'erp.describe_schema', queryTool: 'erp.query_sql', sqlArg: 'sql')]
    class($fingerprint) implements RelationalSchemaProvider {
        public function __construct(private readonly \Closure $fingerprint) {}

        public function scopes(): array
        {
            return ['alsaif'];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(entities: [], relations: []);
        }

        public function catalogFingerprint(): string
        {
            return ($this->fingerprint)();
        }
    };
}

it('returns the fingerprint of a provider that answers inside the bound, silently', function () {
    \Swoole\Coroutine\run(function () {
        $logger = new FpRecordingLogger();
        $provider = fpProvider(function () {
            \Swoole\Coroutine::sleep(0.01);   // yields, like any hooked DB call

            return 'catalog-ok';
        });

        expect(CatalogFingerprint::read($provider, $logger, 'erp.query_sql', 0.5))->toBe('catalog-ok');
        expect($logger->messages)->toBe([]);
    });
});

it('gives up on a provider that outruns the bound instead of waiting it out', function () {
    $logger = new FpRecordingLogger();
    $elapsed = 0.0;

    \Swoole\Coroutine\run(function () use ($logger, &$elapsed) {
        $provider = fpProvider(function () {
            \Swoole\Coroutine::sleep(0.30);   // a cold catalog scan

            return 'too-late';
        });

        $started = microtime(true);
        $fingerprint = CatalogFingerprint::read($provider, $logger, 'erp.query_sql', 0.02);
        $elapsed = microtime(true) - $started;

        expect($fingerprint)->toBe('');
    });

    // The scan takes 0.30s; the bound is 0.02s. Waiting it out is the failure
    // mode being closed, so the elapsed time is the assertion, not decoration.
    expect($elapsed)->toBeLessThan(0.15);
    expect($logger->joined())->toContain('did not answer within 0.02s');
    expect($logger->joined())->toContain('re-extract the schema');
    expect($logger->joined())->not->toContain('later failed');
});

it('reports an abandoned call that later fails — nothing else would surface it', function () {
    // The parent stopped listening, so this failure is otherwise swallowed
    // twice: once by the wait that gave up, once by a coroutine nobody joins.
    $logger = new FpRecordingLogger();

    \Swoole\Coroutine\run(function () use ($logger) {
        $provider = fpProvider(function () {
            \Swoole\Coroutine::sleep(0.10);

            throw new \RuntimeException('connection reset by peer');
        });

        expect(CatalogFingerprint::read($provider, $logger, 'erp.query_sql', 0.02))->toBe('');
    });
    // Coroutine\run() returns only once the abandoned child has finished too.

    expect($logger->joined())->toContain('did not answer within 0.02s');
    expect($logger->joined())->toContain('abandoned catalog fingerprint call');
    expect($logger->joined())->toContain('RuntimeException: connection reset by peer');
});

it('starts no second fetch while one is still running, and releases the latch when it finishes', function () {
    // An abandoned fetch keeps running, and Register is rebuilt on every
    // reconnect (backoff caps at 30s). Without this guard a provider stuck for
    // minutes accumulates one coroutine and one held DB connection per attempt
    // — and since Coroutine\run() waits for all of them, the pile also delays
    // SIGTERM shutdown.
    $logger = new FpRecordingLogger();
    $first = 'unset';
    $second = 'unset';
    $secondProviderCalls = 0;

    \Swoole\Coroutine\run(function () use ($logger, &$first, &$second, &$secondProviderCalls) {
        $slow = fpProvider(function () {
            \Swoole\Coroutine::sleep(0.10);

            return 'catalog-first';
        });

        \Swoole\Coroutine::create(function () use ($slow, $logger, &$first) {
            $first = CatalogFingerprint::read($slow, $logger, 'erp.query_sql', 0.02);
        });

        // The first read has given up by now; its fetch is still running.
        \Swoole\Coroutine::sleep(0.03);

        $second = CatalogFingerprint::read(
            fpProvider(function () use (&$secondProviderCalls) {
                $secondProviderCalls++;

                return 'catalog-second';
            }),
            $logger,
            'erp.query_sql',
            1.0,
        );
    });

    expect($first)->toBe('');                 // bound expired
    expect($second)->toBe('');                // refused rather than queued
    expect($secondProviderCalls)->toBe(0);    // and the provider was never touched
    expect($logger->joined())->toContain('is still running from an earlier attempt');

    // The latch is held by the CALL, not for the process's lifetime: once the
    // abandoned fetch finished (Coroutine\run returned), the next one proceeds.
    $afterwards = 'unset';
    \Swoole\Coroutine\run(function () use ($logger, &$afterwards) {
        $afterwards = CatalogFingerprint::read(fpProvider(fn () => 'catalog-later'), $logger, 'erp.query_sql', 0.5);
    });
    expect($afterwards)->toBe('catalog-later');
});

it('reports a failure that lands in the buffer between the timer firing and the wait resuming', function () {
    // The window is real: capacity 1 and no waiting consumer at that instant,
    // so the child's push SUCCEEDS and it concludes the parent is listening —
    // it stays quiet, while the parent's pop() has already returned false.
    // Nobody would report the failure. Constructed here exactly rather than
    // raced, because a race cannot be provoked deterministically.
    $logger = new FpRecordingLogger();

    \Swoole\Coroutine\run(function () use ($logger) {
        $channel = new \Swoole\Coroutine\Channel(1);
        $channel->push(['error' => new \RuntimeException('connection reset by peer')]);

        CatalogFingerprint::reportLateArrival($channel, $logger, 'erp.query_sql');
    });

    expect($logger->joined())->toContain('abandoned catalog fingerprint call');
    expect($logger->joined())->toContain('RuntimeException: connection reset by peer');
});

it('stays quiet when the value that lands late is a fingerprint, not a failure', function () {
    // Nothing was lost: the Register has gone out with an empty fingerprint,
    // which the core answers by re-extracting, and the next Register reads live.
    $logger = new FpRecordingLogger();

    \Swoole\Coroutine\run(function () use ($logger) {
        $channel = new \Swoole\Coroutine\Channel(1);
        $channel->push(['fingerprint' => 'catalog-late']);

        CatalogFingerprint::reportLateArrival($channel, $logger, 'erp.query_sql');

        // An empty buffer must also be silent, and must not block.
        CatalogFingerprint::reportLateArrival(new \Swoole\Coroutine\Channel(1), $logger, 'erp.query_sql');
    });

    expect($logger->messages)->toBe([]);
});

it('carries a yielding provider\'s live fingerprint onto the wire from inside the daemon\'s coroutine', function () {
    // Production always builds Register inside Coroutine\run() (WorkerCommand
    // enables SWOOLE_HOOK_ALL, then Daemon::run sends buildRegister's frame),
    // so the bounded path — not the unbounded fallback — is the shipped one.
    \Swoole\Coroutine\run(function () {
        $app = ConnectorApp::create()
            ->withLogger(new NullLogger())
            ->agent('erp')
                ->withTool(
                    key: 'erp.describe_schema', name: 'Describe', description: '',
                    inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
                ->withTool(
                    key: 'erp.query_sql', name: 'Query', description: '',
                    inputSchema: ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']]],
                    outputSchema: ['type' => 'object'],
                    handler: fn (array $a, ToolContext $c) => [],
                )
            ->endAgent()
            ->withRelationalSchemaProvider(fpProvider(function () {
                \Swoole\Coroutine::sleep(0.01);

                return 'catalog-live';
            }))
            ->build();

        $parsed = new ConnectorMsg();
        $parsed->mergeFromString(StreamHandler::buildRegister($app)->serializeToString());

        $rel = $parsed->getRegister()?->getRelationalSource();
        expect($rel)->not->toBeNull();
        assert($rel !== null);
        expect($rel->getFingerprint())->toBe('catalog-live');
        expect($rel->getSqlArg())->toBe('sql');
    });
});
