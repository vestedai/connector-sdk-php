<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * The SDK's default logger: one line per event on STDERR.
 *
 * Until 0.13.0 the default was Psr\Log\NullLogger, which meant a connector
 * whose operator never called withLogger() printed NOTHING — not on a rejected
 * Register, not on a refused token, not on a reconnect loop. The diagnostics
 * were all there (Daemon logs 'connected to hub', 'register issue', 'token
 * rejected', 'session ended'); they went to a sink.
 *
 * That is worse than missing logs, because a rejected Register does not close
 * the stream: the connector holds an idle, established connection, so an
 * operator sees healthy TCP, healthy proxy, healthy hub, and no registration,
 * with nothing anywhere saying why. Diagnosing one such case on 2026-09-17 took
 * an evening and never reached an answer.
 *
 * STDERR, not STDOUT: a connector's stdout may carry data in some deployments,
 * and container runtimes capture both. Nothing here is emitted at debug level
 * by default — see $minLevel — so the steady-state cost is a handful of lines
 * per connection, not per tool call.
 *
 * To silence it, inject one explicitly:
 *
 *     $app->withLogger(new \Psr\Log\NullLogger());
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * PSR-3 levels, most severe first. Used to compare against $minLevel — PSR-3
     * defines the level names but no ordering, so the SDK has to carry one.
     *
     * @var list<string>
     */
    private const SEVERITY = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * Null when no writable stream could be opened — see the constructor.
     *
     * @var resource|null
     */
    private $stream;

    /**
     * @param  string  $minLevel  the least severe level that is written; anything
     *                            below it is dropped. Defaults to info, so the
     *                            connection lifecycle is visible but per-call
     *                            debug output is not.
     * @param  resource|null  $stream  defaults to STDERR; injectable for tests.
     */
    public function __construct(
        private readonly string $minLevel = LogLevel::INFO,
        $stream = null,
    ) {
        $resolved = $stream ?? fopen('php://stderr', 'w');

        // fopen() can fail — a container started without stderr, an exhausted
        // descriptor table. Degrade to silence rather than throw: this runs in
        // every ConnectorApp's constructor, and a logger is not worth refusing
        // to start a connector over.
        $this->stream = is_resource($resolved) ? $resolved : null;
    }

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->stream === null || ! $this->passes($level)) {
            return;
        }

        $line = sprintf(
            '[vested-connect] %s %s',
            is_string($level) ? strtoupper($level) : 'LOG',
            (string) $message,
        );

        if ($context !== []) {
            // JSON_PARTIAL_OUTPUT_ON_ERROR: a context value that cannot encode
            // (a resource, a recursive structure) must not cost the whole line.
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' '.($encoded === false ? '{"context":"unencodable"}' : $encoded);
        }

        @fwrite($this->stream, $line.PHP_EOL);
    }

    private function passes(mixed $level): bool
    {
        if (! is_string($level)) {
            return true;
        }

        $want = array_search($this->minLevel, self::SEVERITY, true);
        $got = array_search($level, self::SEVERITY, true);

        // An unknown level is written rather than dropped: a caller using a
        // level this SDK does not know about is not a reason to lose the line.
        if ($want === false || $got === false) {
            return true;
        }

        return $got <= $want;
    }
}
