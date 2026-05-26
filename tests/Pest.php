<?php

declare(strict_types=1);

// Most of our unit tests are I/O-free (schema validation, builders,
// scanner, frame encoding). They don't need to run inside a Swoole
// coroutine. The few that DO (Swoole/GrpcClient, Swoole/Daemon, etc.)
// explicitly wrap their bodies in Swoole\Coroutine\run(...).
//
// We DO want Swoole's runtime hooks active so Guzzle/PDO/etc. tests in
// Swoole/* yield correctly. We don't enable them globally though —
// individual tests opt in via Co::run() and the hook is set there.

uses()->in('Unit', 'Integration');

// Mockery cleanup after each unit test (existing pattern).
uses()->afterEach(fn () => \Mockery::close())->in('Unit');
