<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Hub;

use Vested\Connect\Sdk\Exception\TokenException;
use Vested\Connect\Sdk\Hub\HubClient;

it('rejects an empty token at construction', function () {
    expect(fn () => new HubClient('host:4443', '', insecure: false))
        ->toThrow(TokenException::class, 'token is empty');
});

it('builds a TLS channel for a public addr', function () {
    $client = new HubClient('hub.example.com:4443', 'eyJtest.signature', insecure: false);
    expect($client->hubAddr())->toBe('hub.example.com:4443');
    expect($client->isInsecure())->toBeFalse();
});

it('builds an insecure channel when --insecure flag is set', function () {
    $client = new HubClient('localhost:9092', 'eyJtest.signature', insecure: true);
    expect($client->isInsecure())->toBeTrue();
});
