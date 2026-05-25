<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Hub;

use Grpc\ChannelCredentials;
use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorHubClient;

/**
 * Thin wrapper around the generated ConnectorHubClient. Knows how to
 * build a TLS gRPC channel (or insecure for local dev), and how to
 * open a Connect() bidi stream with the x-connector-token metadata.
 */
final class HubClient
{
    /**
     * Lazily-initialized gRPC stub. We DON'T construct it in __construct
     * because ConnectorHubClient's constructor triggers libgrpc thread
     * pool initialization in the current process. If that process then
     * pcntl_fork()s (as ParentProcess does to spawn the stream-reader),
     * the child inherits a libgrpc state with mutexes locked by threads
     * that don't exist in the fork — and the stream silently misbehaves.
     * See https://github.com/grpc/grpc/issues/31885.
     *
     * By deferring the stub until openStream(), HubClient can be passed
     * across a fork boundary safely: only the process that actually
     * makes a gRPC call (the reader child) initializes libgrpc.
     */
    private ?ConnectorHubClient $grpc = null;

    public function __construct(
        private readonly string $hubAddr,
        private readonly string $token,
        private readonly bool $insecure = false,
    ) {
        if ($token === '') {
            throw new TokenException('token is empty');
        }
    }

    public function hubAddr(): string { return $this->hubAddr; }
    public function isInsecure(): bool { return $this->insecure; }

    /**
     * Open the Connect() bidi stream with the x-connector-token header.
     * Lazily initializes the gRPC stub on first call.
     */
    public function openStream(): \Grpc\BidiStreamingCall
    {
        if ($this->grpc === null) {
            $creds = $this->insecure
                ? ChannelCredentials::createInsecure()
                : ChannelCredentials::createSsl();
            $this->grpc = new ConnectorHubClient($this->hubAddr, [
                'credentials' => $creds,
            ]);
        }
        return $this->grpc->Connect([
            'x-connector-token' => [$this->token],
        ]);
    }
}
