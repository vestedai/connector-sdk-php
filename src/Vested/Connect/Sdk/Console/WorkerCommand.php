<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Process\ParentProcess;

#[AsCommand(name: 'worker', description: 'Run the connector daemon')]
final class WorkerCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('bootstrap', 'b', InputOption::VALUE_REQUIRED, 'Path to bootstrap.php that returns ConnectorApp');
        $this->addOption('hub-addr', null, InputOption::VALUE_REQUIRED, 'Hub address host:port', null);
        $this->addOption('insecure', null, InputOption::VALUE_NONE, 'Use plaintext gRPC (local dev only)');
        $this->addOption('token-stdin', null, InputOption::VALUE_NONE, 'Read token from stdin (systemd-creds compatible)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bootstrapPath = (string) $input->getOption('bootstrap');
        if ($bootstrapPath === '' || ! is_file($bootstrapPath)) {
            $output->writeln("<error>--bootstrap path not found: {$bootstrapPath}</error>");
            return Command::FAILURE;
        }

        try {
            /** @var ConnectorApp $app */
            $app = require $bootstrapPath;
        } catch (ConfigException $e) {
            $output->writeln('<error>bootstrap failed: ' . $e->getMessage() . '</error>');
            return 78; // EX_CONFIG
        }
        if (! $app instanceof ConnectorApp) {
            $output->writeln('<error>bootstrap must return a Vested\\Connect\\Sdk\\ConnectorApp instance</error>');
            return 78;
        }

        $token = $input->getOption('token-stdin')
            ? trim((string) fgets(STDIN))
            : (string) getenv('VESTED_CONNECTOR_TOKEN');

        if ($token === '') {
            $output->writeln('<error>token is empty — set VESTED_CONNECTOR_TOKEN or use --token-stdin</error>');
            return 78;
        }

        $hubAddr = (string) ($input->getOption('hub-addr') ?: (getenv('VESTED_CONNECTOR_HUB') ?: 'ai-connect.alsaifgallery.com:4443'));
        $insecure = (bool) $input->getOption('insecure');
        if ($insecure) {
            $output->writeln('<comment>WARNING: --insecure dialing plaintext gRPC; do not use in production</comment>');
        }

        try {
            $proc = new ParentProcess(
                app: $app, token: $token, hubAddr: $hubAddr,
                insecure: $insecure, logger: $app->logger(),
            );
            return $proc->run();
        } catch (TokenException $e) {
            $output->writeln('<error>token problem: ' . $e->getMessage() . '</error>');
            return 78;
        } catch (\Throwable $e) {
            $output->writeln('<error>fatal: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
