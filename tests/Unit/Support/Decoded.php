<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Support;

use Google\Protobuf\Struct;
use Google\Protobuf\Value;

/**
 * Typed unwrapping for messages rebuilt from protobuf bytes.
 *
 * protoc's generated PHP carries no return types: a singular message getter
 * can hand back null, and a repeated or map field yields bare `object`. A test
 * that chains straight off one of those is green only for as long as the field
 * happens to be populated — the day it is not, the failure is a fatal
 * "method call on null" pointing at the assertion rather than at the message
 * that lost the field.
 *
 * These helpers make the expectation explicit: the value is named, a missing
 * or wrong-typed one fails as a sentence, and the caller gets a type PHPStan
 * can check the rest of the chain against.
 */
final class Decoded
{
    /**
     * A singular message field that must be set.
     *
     * @template T of object
     *
     * @param  T|null  $value
     * @return T
     */
    public static function present(?object $value, string $what): object
    {
        if ($value === null) {
            throw new \RuntimeException("expected {$what} on the decoded message, but it was not set");
        }

        return $value;
    }

    /**
     * A repeated message field as a typed list. Each element's class is
     * checked, so a field decoded into the wrong message type fails here.
     *
     * @template T of object
     *
     * @param  iterable<mixed>  $field
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public static function repeated(iterable $field, string $class, string $what): array
    {
        $out = [];
        foreach ($field as $item) {
            if (! $item instanceof $class) {
                throw new \RuntimeException(sprintf(
                    'expected %s in %s, got %s',
                    $class,
                    $what,
                    get_debug_type($item),
                ));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * A Struct's fields as a typed map. Struct is the shape meta and display
     * travel in, and its value type is what the type-dispatch in
     * ConnectionOpDispatcher::toValue() exists to get right — so the tests
     * that pin it need the Value, not an object.
     *
     * @return array<string, Value>
     */
    public static function structFields(?Struct $struct, string $what): array
    {
        $fields = [];
        foreach (self::present($struct, $what)->getFields() as $key => $value) {
            if (! $value instanceof Value) {
                throw new \RuntimeException(sprintf(
                    'expected a google.protobuf.Value at %s[%s], got %s',
                    $what,
                    (string) $key,
                    get_debug_type($value),
                ));
            }
            $fields[(string) $key] = $value;
        }

        return $fields;
    }
}
