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
    private readonly ConnectorHubClient $grpc;

    public function __construct(
        private readonly string $hubAddr,
        private readonly string $token,
        private readonly bool $insecure = false,
    ) {
        if ($token === '') {
            throw new TokenException('token is empty');
        }
        $creds = $this->insecure
            ? ChannelCredentials::createInsecure()
            : ChannelCredentials::createSsl();
        $this->grpc = new ConnectorHubClient($hubAddr, [
            'credentials' => $creds,
        ]);
    }

    public function hubAddr(): string { return $this->hubAddr; }
    public function isInsecure(): bool { return $this->insecure; }

    /**
     * Open the Connect() bidi stream with the x-connector-token header.
     * Returns the BidiStreamingCall object.
     */
    public function openStream(): \Grpc\BidiStreamingCall
    {
        return $this->grpc->Connect([
            'x-connector-token' => [$this->token],
        ]);
    }
}
