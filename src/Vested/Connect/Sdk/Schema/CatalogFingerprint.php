<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Schema;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Reads a relational source's catalog fingerprint for the Register frame,
 * bounded, and never at the cost of the declaration itself.
 *
 * Two rules, both deliberate:
 *
 * 1. ON ANY FAILURE, STILL DECLARE — with an empty fingerprint. A source
 *    database that is unreachable or cold at connector startup is normal and
 *    transient. An empty fingerprint makes the core re-extract: expensive, but
 *    correct and visible in its own logs. Omitting the declaration instead
 *    would silently disable schema extraction AND the SQL gate for the whole
 *    session, with nothing anywhere reporting that governance had stopped.
 *    Silent disablement is the failure class this declaration exists to close,
 *    so it must never be the response to a transient error.
 *
 * 2. BOUND THE WAIT. This runs BEFORE Register is sent, and the hub's 30s idle
 *    timer is already running: it starts at HelloAck with Register as the next
 *    expected frame, and the connector's heartbeat only starts after
 *    RegisterAck, so nothing resets it meanwhile. A catalog scan is not cheap.
 *    Unbounded, a cold database outlasts the idle timer, the hub sends
 *    GoAway{reason:"idle"} before Register is ever sent, the supervisor
 *    reconnects, and it repeats: the connector's ENTIRE tool surface stays
 *    offline and the only log line names idleness, not the fingerprint. Same
 *    silent, misattributed disablement as rule 1, reached by hanging instead of
 *    by throwing.
 *
 * How the bound works here, and what it does NOT cover: the fetch runs in a
 * child coroutine and the caller waits on a channel with a timeout, which is
 * this runtime's equivalent of racing a delay. It is COOPERATIVE. The worker
 * enables SWOOLE_HOOK_ALL before the daemon starts (WorkerCommand), so a
 * provider doing ordinary I/O — PDO, mysqli, curl, streams, Coroutine::sleep —
 * yields and the bound holds. A provider that blocks the thread without
 * yielding (an unhooked driver extension, or a CPU-bound scan) cannot be
 * bounded at all: PHP has no threads and no preemption, so the timer that would
 * fire is itself waiting behind the call. Nothing in this SDK can fix that; a
 * provider must not block the scheduler. That limit is the honest difference
 * from the .NET SDK, whose Task.Delay runs on another thread.
 */
final class CatalogFingerprint
{
    /**
     * Mirrors the .NET SDK's DefaultFingerprintTimeout. Comfortably inside the
     * hub's 30s idle window, with room for the rest of the handshake.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * @param  string  $queryTool  named in the warnings — it is how an operator
     *                             identifies WHICH source failed to fingerprint
     */
    public static function read(
        RelationalSchemaProvider $provider,
        LoggerInterface $logger,
        string $queryTool,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ): string {
        if (Coroutine::getCid() < 1) {
            // No scheduler running, so no bound is possible: a synchronous call
            // in a single-threaded process cannot be interrupted from inside
            // the process (pcntl_alarm could, but it would hijack the
            // process-global signal disposition that SignalHandler owns, and
            // trade a hang for a half-torn shutdown). Only reachable outside the
            // daemon — production always builds Register inside the
            // Swoole\Coroutine\run() that WorkerCommand starts.
            try {
                return $provider->catalogFingerprint();
            } catch (\Throwable $e) {
                self::warnUnavailable($logger, $queryTool, self::describeThrowable($e));

                return '';
            }
        }

        $channel = new Channel(1);

        // The channel is also how the child learns the parent gave up: when the
        // bound expires the parent CLOSES it, and every later push returns
        // false. That keeps the hand-off in one object instead of a flag the
        // two coroutines have to agree about.
        Coroutine::create(static function () use ($channel, $provider, $logger, $queryTool): void {
            try {
                $fingerprint = $provider->catalogFingerprint();
            } catch (\Throwable $e) {
                if (! $channel->push(['error' => $e])) {
                    // Push refused = the parent already gave up, so this is a
                    // provider that outran the bound and THEN failed. Without
                    // this line the failure is swallowed twice — once by the
                    // wait that stopped listening, once by a coroutine nobody
                    // joins — and the operator has nothing at all to explain why
                    // the catalog never gets fingerprinted.
                    $logger->warning(
                        sprintf(
                            'the abandoned catalog fingerprint call for relational source \'%s\' '
                            . 'later failed: %s. The connector registered without waiting for it.',
                            $queryTool,
                            self::describeThrowable($e),
                        ),
                        ['query_tool' => $queryTool, 'exception' => $e::class],
                    );
                }

                return;
            }

            // A late SUCCESS is deliberately quiet: the Register that needed it
            // has already gone out with an empty fingerprint, which the core
            // answers by re-extracting, and the next Register picks the value up
            // live. Nothing is lost, so nothing needs saying.
            $channel->push(['fingerprint' => $fingerprint]);
        });

        $result = $channel->pop($timeoutSeconds);

        if (! is_array($result)) {
            // false = the bound expired (Channel::pop's timeout signal).
            $channel->close();
            self::warnUnavailable(
                $logger,
                $queryTool,
                sprintf('it did not answer within %ss', rtrim(rtrim(number_format($timeoutSeconds, 3, '.', ''), '0'), '.')),
            );

            return '';
        }

        $error = $result['error'] ?? null;
        if ($error instanceof \Throwable) {
            self::warnUnavailable($logger, $queryTool, self::describeThrowable($error));

            return '';
        }

        $fingerprint = $result['fingerprint'] ?? null;

        return is_string($fingerprint) ? $fingerprint : '';
    }

    /**
     * The two causes are worded distinctly on purpose: "did not answer" and
     * "threw" send an operator to different places — a slow catalog scan versus
     * a broken connection or a bug in the provider.
     */
    private static function warnUnavailable(LoggerInterface $logger, string $queryTool, string $cause): void
    {
        $logger->warning(
            sprintf(
                'catalog fingerprint unavailable for relational source \'%s\' — %s; registering '
                . 'with an empty fingerprint, which makes the platform re-extract the schema',
                $queryTool,
                $cause,
            ),
            ['query_tool' => $queryTool, 'cause' => $cause],
        );
    }

    private static function describeThrowable(\Throwable $e): string
    {
        return sprintf('%s: %s', $e::class, $e->getMessage());
    }
}
