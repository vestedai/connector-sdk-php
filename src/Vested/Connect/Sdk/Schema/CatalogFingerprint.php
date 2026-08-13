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
 * Three rules, all deliberate:
 *
 * 1. ON ANY FAILURE, STILL DECLARE — with an empty fingerprint. A source
 *    database that is unreachable or cold at connector startup is normal and
 *    transient. An empty fingerprint is meant to cost a re-extraction:
 *    expensive, but correct and visible in its own logs. Omitting the
 *    declaration instead would silently disable schema extraction AND the SQL
 *    gate for the whole session, with nothing anywhere reporting that
 *    governance had stopped. Silent disablement is the failure class this
 *    declaration exists to close, so it must never be the response to a
 *    transient error.
 *
 *    CAVEAT, true as of 2026-08-13: that re-extraction does not yet happen end
 *    to end. When the database recovers, the connector re-registers with the
 *    real catalog hash but with unchanged agents and tools — and
 *    baseline_fingerprint covers agents and tools only. The hub forwards the
 *    changed declaration (it stopped short-circuiting on the baseline alone),
 *    but the core then de-duplicates by that same unchanged fingerprint and
 *    does not re-persist the new catalog hash, so extraction stays pinned to
 *    the empty value until something the baseline DOES cover changes.
 *    Withdrawing this caveat is part of the core-side task that fixes the
 *    de-duplication; until then, treat an empty fingerprint as a transient
 *    state to get out of, not a free one.
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
 * 3. ONE FETCH AT A TIME. A fetch this class stops waiting for keeps running —
 *    it is not cancelled, because Swoole cannot interrupt a call that never
 *    yields anyway, and cancelling one mid-query risks leaving the provider's
 *    connection in a torn state while logging a failure WE caused, which reads
 *    identically to the provider's own. So the accumulation is bounded at the
 *    other end instead: while one fetch is outstanding, the next Register
 *    declares immediately with an empty fingerprint rather than starting
 *    another.
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
     * True while a fetch started here is still running — including one this
     * class has already stopped waiting for. Process-wide because a worker runs
     * one connector with one provider, and the resource being protected (the
     * provider's connection, and the coroutine that delays shutdown) is
     * process-wide too. Set before the child starts, cleared BY the child.
     */
    private static bool $inFlight = false;

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
            // No scheduler running, so no bound: a synchronous call in a
            // single-threaded process has nothing to race against.
            //
            // pcntl_async_signals(true) + pcntl_alarm() is the tempting answer,
            // and it is NOT rejected because it cannot interrupt — measured on
            // this host, it interrupts sleep(), usleep() and a CPU-bound loop
            // alike. It is rejected because (a) Swoole's own guidance is not to
            // mix pcntl's signal dispatch with Process::signal in one process,
            // and this class runs in a Swoole worker — received guidance,
            // unverified here, and it is (b) and (c) that decide it; (b) the
            // case that actually matters — a driver's C-level read that retries
            // EINTR internally — still cannot be interrupted, because PHP
            // dispatches signals only at VM opcode boundaries; and (c) throwing
            // from an async handler unwinds at whatever opcode happened to be
            // executing, which under a coroutine scheduler may not even be
            // inside the provider's frame. It would buy an unreliable bound for
            // a real risk.
            //
            // Only reachable outside the daemon — production always builds
            // Register inside the Swoole\Coroutine\run() WorkerCommand starts.
            try {
                return $provider->catalogFingerprint();
            } catch (\Throwable $e) {
                self::warnUnavailable($logger, $queryTool, self::describeThrowable($e));

                return '';
            }
        }

        // ONE fetch at a time, process-wide. An abandoned fetch keeps running
        // (see below on why it is not cancelled), and Register is rebuilt on
        // every reconnect — with backoff capped at 30s, a provider stuck for
        // minutes would otherwise accumulate one live coroutine and one held DB
        // connection per attempt. Worse, Coroutine\run() does not return until
        // they all finish, so the pile also delays SIGTERM shutdown past the
        // daemon's clean exit. Registering immediately with an empty
        // fingerprint is the same contract as the timeout, and it bounds this
        // to a single outstanding fetch.
        if (self::$inFlight) {
            $logger->warning(
                sprintf(
                    'a catalog fingerprint fetch for relational source \'%s\' is still running '
                    . 'from an earlier attempt; registering with an empty fingerprint rather '
                    . 'than starting a second one, which makes the platform re-extract the schema',
                    $queryTool,
                ),
                ['query_tool' => $queryTool],
            );

            return '';
        }

        $channel = new Channel(1);

        // The channel is also how the child learns the parent gave up: when the
        // bound expires the parent CLOSES it, and every later push returns
        // false. That keeps the hand-off in one object instead of a flag the
        // two coroutines have to agree about.
        self::$inFlight = true;
        $childCid = Coroutine::create(static function () use ($channel, $provider, $logger, $queryTool): void {
            try {
                try {
                    $fingerprint = $provider->catalogFingerprint();
                } finally {
                    // Released by the CHILD, not the parent: the parent may
                    // return long before this call does, and it is precisely
                    // the still-running call that must block the next one.
                    self::$inFlight = false;
                }
            } catch (\Throwable $e) {
                if (! $channel->push(['error' => $e])) {
                    // Push refused = the parent already gave up, so this is a
                    // provider that outran the bound and THEN failed. Without
                    // this line the failure is swallowed twice — once by the
                    // wait that stopped listening, once by a coroutine nobody
                    // joins — and the operator has nothing at all to explain why
                    // the catalog never gets fingerprinted.
                    self::warnAbandonedFailure($logger, $queryTool, $e);
                }

                return;
            }

            // A late SUCCESS is deliberately quiet: the Register that needed it
            // has already gone out with an empty fingerprint, which the core
            // answers by re-extracting, and the next Register picks the value up
            // live. Nothing is lost, so nothing needs saying.
            $channel->push(['fingerprint' => $fingerprint]);
        });

        // Coroutine::create() reports a refused coroutine — the scheduler's
        // max_coroutine limit — with a PHP warning and a FALSE return, never an
        // exception. Unchecked, that is the worst failure in this file: the
        // child never runs, so the finally that is the ONLY place clearing the
        // latch never runs either; nothing ever pushes, so pop() times out and
        // read() returns '' looking exactly like a merely slow provider; and
        // $inFlight stays true for the rest of the process, refusing EVERY
        // later Register. Permanent and silent, where the accumulation this
        // latch exists to bound is temporary and noisy — the latch would have
        // become the failure class it was added to prevent.
        if ($childCid === false) {
            self::$inFlight = false;
            $channel->close();
            self::warnUnavailable(
                $logger,
                $queryTool,
                'the runtime refused to start a coroutine for it (the scheduler\'s '
                . 'max_coroutine limit is reached)',
            );

            return '';
        }

        $result = $channel->pop($timeoutSeconds);

        if (! is_array($result)) {
            // false = the bound expired (Channel::pop's timeout signal).
            self::reportLateArrival($channel, $logger, $queryTool);
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
     * Report a value that landed in the buffer AFTER the bound expired but
     * BEFORE this coroutine was rescheduled.
     *
     * That window is real: the channel has capacity 1 and no waiting consumer
     * at that instant, so the child's push SUCCEEDS and the child therefore
     * concludes the parent was still listening — it stays quiet, and the
     * parent's own pop() has already returned false. A late FAILURE would then
     * be reported by nobody, which is exactly the hole the three distinct
     * wordings exist to close. Drain the buffer here so it is reported by the
     * same wording the child would have used.
     *
     * Public only as a test seam: the race cannot be provoked deterministically
     * from a test, but the state it produces (a value sitting in the buffer at
     * timeout) can be constructed exactly.
     *
     * @internal
     */
    public static function reportLateArrival(Channel $channel, LoggerInterface $logger, string $queryTool): void
    {
        // length() first: Channel::pop(0.0) treats 0 as "no timeout" and blocks
        // forever, the same Swoole quirk OutboundChannel documents.
        if ($channel->length() < 1) {
            return;
        }

        $late = $channel->pop(0.001);
        $error = is_array($late) ? ($late['error'] ?? null) : null;

        if ($error instanceof \Throwable) {
            self::warnAbandonedFailure($logger, $queryTool, $error);
        }
        // A late success stays quiet here for the same reason it does in the
        // child: the Register has already gone out and the next one reads live.
    }

    /**
     * The three causes are worded distinctly on purpose: "did not answer",
     * "threw", and "the abandoned call later failed" send an operator to three
     * different places — a slow catalog scan, a broken connection or a bug in
     * the provider, and a provider that outran the bound and then died.
     */
    private static function warnAbandonedFailure(LoggerInterface $logger, string $queryTool, \Throwable $e): void
    {
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
