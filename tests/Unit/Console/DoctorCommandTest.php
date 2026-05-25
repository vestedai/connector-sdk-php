<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vested\Connect\Sdk\Console\DoctorCommand;

it('lists each required extension', function () {
    $tester = new CommandTester((new Application())->add(new DoctorCommand()) ?? throw new \LogicException());
})->skip(true, 'See execute-driven variant below — needed because add() returns void/null on different Symfony versions');

it('reports each required extension and overall success/failure', function () {
    $app = new Application();
    $app->add(new DoctorCommand());
    $tester = new CommandTester($app->find('doctor'));
    $tester->execute([]);
    $out = $tester->getDisplay();
    foreach (['grpc', 'protobuf', 'pcntl', 'sockets', 'json'] as $ext) {
        expect($out)->toContain($ext);
    }
});
