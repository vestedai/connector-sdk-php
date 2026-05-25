<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vested\Connect\Sdk\Console\VersionCommand;

it('prints SDK + PHP versions', function () {
    $app = new Application();
    $app->add(new VersionCommand());
    $cmd = $app->find('version');
    $tester = new CommandTester($cmd);
    $tester->execute([]);
    expect($tester->getStatusCode())->toBe(0);
    $out = $tester->getDisplay();
    expect($out)->toContain('vested-ai/connector-sdk-php');
    expect($out)->toContain('PHP ');
});
