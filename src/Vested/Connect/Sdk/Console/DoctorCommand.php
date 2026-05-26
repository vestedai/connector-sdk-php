<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'doctor', description: 'Verify required PHP extensions are loaded')]
final class DoctorCommand extends Command
{
    private const REQUIRED_EXTS = ['swoole', 'json', 'openssl'];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = [];
        foreach (self::REQUIRED_EXTS as $ext) {
            $loaded = extension_loaded($ext);
            $output->writeln(sprintf('  %s %s', $loaded ? '✓' : '✗', $ext));
            if (! $loaded) {
                $failed[] = $ext;
            }
        }
        $output->writeln('PHP ' . PHP_VERSION);
        if (! empty($failed)) {
            $output->writeln('<error>missing: ' . implode(', ', $failed) . '</error>');
            $output->writeln('install with: pecl install swoole  (or your distro package manager)');
            return Command::FAILURE;
        }
        $output->writeln('<info>all required extensions loaded</info>');
        return Command::SUCCESS;
    }
}
