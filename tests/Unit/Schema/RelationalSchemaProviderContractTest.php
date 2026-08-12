<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Schema;

use Vested\Connect\Sdk\Schema\CanonicalSchema;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;

it('can be implemented by an anonymous class and returns a canonical schema', function () {
    $provider = new class implements RelationalSchemaProvider {
        public function scopes(): array
        {
            return ['magento'];
        }

        public function describe(string $scopeKey): CanonicalSchema
        {
            return new CanonicalSchema(
                entities: [['logical_name' => 'catalog_product', 'scope_key' => $scopeKey]],
                relations: [],
            );
        }

        public function catalogFingerprint(): string
        {
            return 'cat-abc';
        }
    };

    expect($provider->scopes())->toBe(['magento']);
    expect($provider->describe('magento')->entities[0]['logical_name'])->toBe('catalog_product');
    expect($provider->catalogFingerprint())->toBe('cat-abc');
});
