<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version', description: 'Print SDK version + PHP version')]
final class VersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../../../composer.json'), true);
        $sdkVer = (string) ($composer['version'] ?? '0.1.0-dev');
        $output->writeln("vested-ai/connector-sdk-php {$sdkVer}");
        $output->writeln('PHP ' . PHP_VERSION);
        return Command::SUCCESS;
    }
}
