<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vested\Connect\Sdk\ConnectorApp;

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

        // The Swoole runtime is bootstrapped here; bootstrap.php is loaded
        // inside Co::run() so the user's container init can yield on async
        // I/O (DB warmup, etc.).
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $exit = 0;
        \Swoole\Coroutine\run(function () use ($bootstrapPath, $input, $output, &$exit) {
            try {
                /** @var \Vested\Connect\Sdk\ConnectorApp $app */
                $app = require $bootstrapPath;
            } catch (\Throwable $e) {
                $output->writeln('<error>bootstrap failed: ' . $e->getMessage() . '</error>');
                $exit = 78;
                return;
            }
            if (! $app instanceof ConnectorApp) {
                $output->writeln('<error>bootstrap must return a Vested\\Connect\\Sdk\\ConnectorApp instance</error>');
                $exit = 78;
                return;
            }

            $token   = $input->getOption('token-stdin')
                ? trim((string) fgets(STDIN))
                : (string) getenv('VESTED_CONNECTOR_TOKEN');
            if ($token === '') {
                $output->writeln('<error>token is empty — set VESTED_CONNECTOR_TOKEN or use --token-stdin</error>');
                $exit = 78;
                return;
            }

            $hubAddr = (string) ($input->getOption('hub-addr') ?: (getenv('VESTED_CONNECTOR_HUB') ?: ''));
            if ($hubAddr === '') {
                $output->writeln('<error>hub address is empty — set VESTED_CONNECTOR_HUB or pass --hub-addr</error>');
                $exit = 78;
                return;
            }

            $insecure = (bool) $input->getOption('insecure');
            $exit = $app->runSwooleDaemon($token, $hubAddr, $insecure);
        });

        return $exit;
    }
}
