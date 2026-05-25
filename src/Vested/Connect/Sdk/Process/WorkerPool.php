<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Process;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplQueue;
use Vested\Connect\Sdk\Exception\ConnectorException;

/**
 * Pre-forked worker pool. Spawns N children at start(); each child
 * runs $spawn($childSocket). Parent holds the matching socket end
 * for each worker in an idle queue.
 *
 * acquire() pops an idle socket. release() puts it back.
 *
 * On SIGCHLD, the dead worker is respawned and a fresh socketpair set up.
 * (Auto-respawn is a known-gap follow-up; v1 just exposes shutdown().)
 *
 * @internal
 */
final class WorkerPool
{
    /** @var array<int, resource> pid → parent-side socket */
    private array $workerSockets = [];

    /** @var SplQueue<resource> idle parent-side sockets */
    private SplQueue $idleQueue;

    /** @var Closure(resource):void  the child entrypoint */
    private Closure $spawn;

    private bool $started = false;

    public function __construct(
        private readonly int $size,
        Closure $spawn,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->spawn = $spawn;
        $this->idleQueue = new SplQueue();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        for ($i = 0; $i < $this->size; $i++) {
            $this->forkOne();
        }
        $this->started = true;
    }

    /** @return resource */
    public function acquire()
    {
        if ($this->idleQueue->isEmpty()) {
            throw new ConnectorException('WorkerPool::acquire called with no idle workers');
        }
        return $this->idleQueue->dequeue();
    }

    /** @param resource $socket */
    public function release($socket): void
    {
        $this->idleQueue->enqueue($socket);
    }

    public function idleCount(): int
    {
        return $this->idleQueue->count();
    }

    /** Returns all parent-side sockets so the caller can stream_select() over them. @return list<resource> */
    public function allSockets(): array
    {
        return array_values($this->workerSockets);
    }

    public function shutdown(int $timeoutSeconds): void
    {
        foreach ($this->workerSockets as $pid => $sock) {
            @fclose($sock);
            posix_kill($pid, SIGTERM);
        }
        $deadline = time() + $timeoutSeconds;
        while (! empty($this->workerSockets) && time() < $deadline) {
            foreach ($this->workerSockets as $pid => $_) {
                $exited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($exited === $pid) {
                    unset($this->workerSockets[$pid]);
                }
            }
            if (! empty($this->workerSockets)) {
                usleep(50_000);
            }
        }
        foreach (array_keys($this->workerSockets) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($this->workerSockets[$pid]);
        }
    }

    private function forkOne(): void
    {
        [$parentEnd, $childEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ConnectorException('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child
            @fclose($parentEnd);
            ($this->spawn)($childEnd);
            exit(0);
        }
        // Parent
        @fclose($childEnd);
        stream_set_blocking($parentEnd, true);
        $this->workerSockets[$pid] = $parentEnd;
        $this->idleQueue->enqueue($parentEnd);
        $this->logger->info('forked worker', ['pid' => $pid]);
    }
}
