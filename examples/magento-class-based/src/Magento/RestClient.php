<?php

declare(strict_types=1);

namespace Acme\Commerce\Magento;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin Guzzle wrapper for the Magento 2 REST API.
 *
 * Authenticates every request with the admin integration token.
 * Maps HTTP 401/403/404/5xx to typed RuntimeExceptions so tool
 * handlers can catch specific failure modes.
 */
final class RestClient
{
    private readonly Client $guzzle;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $integrationToken,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Client $guzzle = null,
        int $timeoutSeconds = 10,
    ) {
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new \InvalidArgumentException('MAGENTO_BASE_URL must use https://');
        }
        $this->guzzle = $guzzle ?? new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout'  => $timeoutSeconds,
        ]);
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $path, array $options): mixed
    {
        $t = microtime(true);
        try {
            $response = $this->guzzle->request($method, $path, array_merge($options, [
                'headers'     => [
                    'Authorization' => "Bearer {$this->integrationToken}",
                    'Accept'        => 'application/json',
                ],
                'http_errors' => false,
            ]));
        } catch (ConnectException $e) {
            throw new \RuntimeException("Magento connect failed: {$e->getMessage()}", 0, $e);
        } catch (RequestException $e) {
            throw new \RuntimeException("Magento request failed: {$e->getMessage()}", 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $this->logger->debug('magento rest', [
            'method' => $method, 'path' => $path,
            'status' => $status, 'ms' => (int) ((microtime(true) - $t) * 1000),
        ]);

        if ($status >= 200 && $status < 300) {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        }

        $msg = "Magento {$method} {$path} → {$status}: " . substr($body, 0, 200);
        throw match (true) {
            $status === 401 || $status === 403 => new \RuntimeException("Unauthorized: {$msg}"),
            $status === 404 => new \RuntimeException("Not found: {$msg}"),
            default         => new \RuntimeException($msg),
        };
    }
}
