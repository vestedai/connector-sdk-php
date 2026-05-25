<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Exception;

use Vested\Connect\Sdk\Exception\TokenException;

it('redacts the token to first/last 4 chars in toString', function () {
    $e = new TokenException('jwt rejected', token: 'eyJ0eXAiOiJKV1Qi.MIDDLE_PART.SIGNATURE_END_4321');
    $str = (string) $e;
    expect($str)->toContain('eyJ0…4321');
    expect($str)->not->toContain('MIDDLE_PART');
});

it('handles short tokens without crashing', function () {
    $e = new TokenException('jwt rejected', token: 'abc');
    expect((string) $e)->toContain('[redacted-short]');
});

it('omits token line when no token given', function () {
    $e = new TokenException('jwt rejected');
    $str = (string) $e;
    expect($str)->toContain('jwt rejected');
    expect($str)->not->toContain('token:');
});
