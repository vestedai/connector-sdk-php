<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Log;

use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Log\StderrLogger;

/**
 * @return array{0: StderrLogger, 1: resource}
 */
function stderrLoggerOnMemory(string $minLevel = LogLevel::INFO): array
{
    $stream = fopen('php://memory', 'w+');
    if (! is_resource($stream)) {
        throw new \RuntimeException('could not open a memory stream');
    }

    return [new StderrLogger($minLevel, $stream), $stream];
}

function stderrLoggerRead(mixed $stream): string
{
    if (! is_resource($stream)) {
        return '';
    }
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('writes the level, the message and the context', function () {
    [$logger, $stream] = stderrLoggerOnMemory();

    $logger->info('connected to hub', ['connector_id' => 'platform:1', 'sdk' => '0.13.0']);

    $out = stderrLoggerRead($stream);
    expect($out)->toContain('[vested-connect]')
        ->and($out)->toContain('INFO')
        ->and($out)->toContain('connected to hub')
        ->and($out)->toContain('platform:1');
});

it('drops levels below the threshold but keeps the ones at or above it', function () {
    [$logger, $stream] = stderrLoggerOnMemory(LogLevel::INFO);

    $logger->debug('per-call noise');
    $logger->info('lifecycle');
    $logger->error('register rejected');

    $out = stderrLoggerRead($stream);
    expect($out)->not->toContain('per-call noise')
        ->and($out)->toContain('lifecycle')
        ->and($out)->toContain('register rejected');
});

it('keeps a line whose context cannot be encoded', function () {
    [$logger, $stream] = stderrLoggerOnMemory();

    // A resource has no JSON representation. The line still has to arrive —
    // losing 'token rejected' because its context held something awkward is
    // exactly the failure this logger exists to prevent.
    $handle = fopen('php://memory', 'r');
    $logger->error('token rejected', ['stream' => $handle]);

    expect(stderrLoggerRead($stream))->toContain('token rejected');
});

it('is silent on an unusable stream, rather than throwing', function () {
    // The degraded path: a container with no usable stderr must not stop a
    // connector from starting. A closed handle is the type-correct way to
    // reach it — is_resource() is false for one, exactly as for a failed
    // fopen().
    $closed = fopen('php://memory', 'w');
    if (! is_resource($closed)) {
        throw new \RuntimeException('could not open a memory stream');
    }
    fclose($closed);

    (new StderrLogger(LogLevel::INFO, $closed))->error('should not throw');
})->throwsNoExceptions();

/*
 * The regression this whole class exists for.
 *
 * ConnectorApp defaulted $logger to NullLogger, so every diagnostic the Daemon
 * already emitted — 'connected to hub', 'register issue', 'token rejected',
 * 'session ended' — went to a sink unless the operator happened to call
 * withLogger(). A connector could fail fatally and print nothing at all.
 */
it('is the default logger on a freshly created ConnectorApp', function () {
    expect(ConnectorApp::create()->logger())->toBeInstanceOf(StderrLogger::class);
});

it('still lets an operator choose silence explicitly', function () {
    $app = ConnectorApp::create()->withLogger(new NullLogger());

    expect($app->logger())->toBeInstanceOf(NullLogger::class);
});
