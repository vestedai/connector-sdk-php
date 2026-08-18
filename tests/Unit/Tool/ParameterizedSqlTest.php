<?php

declare(strict_types=1);

use Vested\Connect\Sdk\Tool\ParameterizedSql;

it('passes scalars through and turns a list into ONE json string', function () {
    $out = ParameterizedSql::normalise(['from' => '2026-01-01', 'locs' => ['A', 'B']]);

    expect($out['from'])->toBe('2026-01-01')
        // ONE parameter, not two: the database expands it via JSON_TABLE.
        ->and($out['locs'])->toBe('["A","B"]');
});

it('passes other scalar types and null through unchanged', function () {
    $out = ParameterizedSql::normalise(['n' => 42, 'f' => 3.5, 'b' => true, 'z' => null]);

    expect($out['n'])->toBe(42)
        ->and($out['f'])->toBe(3.5)
        ->and($out['b'])->toBeTrue()
        ->and($out['z'])->toBeNull();
});

it('refuses a value it cannot bind rather than substituting it', function () {
    expect(fn () => ParameterizedSql::normalise(['x' => ['a' => 1]]))
        ->toThrow(InvalidArgumentException::class, 'x');
});

it('leaves the statement untouched when a value contains SQL', function () {
    // The property the whole design rests on. normalise() moves VALUES around
    // and must never be a place a value can reach the statement.
    $out = ParameterizedSql::normalise(['from' => "2026-01-01'; DROP TABLE x --"]);

    expect($out['from'])->toBe("2026-01-01'; DROP TABLE x --");
});

it('mutation check: a normalised value that were ever altered would break the injection test', function () {
    // Not a separate feature — this pins the previous test's assertion to a
    // value that could not pass BY ACCIDENT (e.g. a normalise() that trims,
    // escapes quotes, or truncates at the comment marker would still look
    // plausible to a weaker assertion). Any single-character alteration here
    // fails.
    $raw = "2026-01-01'; DROP TABLE x --";
    $out = ParameterizedSql::normalise(['from' => $raw]);
    $normalised = (string) $out['from'];

    expect(strlen($normalised))->toBe(strlen($raw))
        ->and($normalised)->toBe($raw)
        ->and($normalised === $raw)->toBeTrue();
});

it('produces a schema fragment that ACCEPTS a params object', function () {
    // Two consumers: a connector using #[Tool(inputSchema: [...])] merges this
    // directly, and a connector using inputSchemaFile (like the live ecommerce
    // one) keeps its hand-written JSON equal to it — Task 9 asserts that
    // equality, so the hand-written copy cannot drift from the SDK's contract.
    // The hub validates args against the tool's input schema before dispatch,
    // so a hand-written additionalProperties:false silently rejects every
    // parameterized call. This fragment is what stops an author getting it wrong.
    $frag = ParameterizedSql::inputSchemaFragment('Params');

    expect($frag)->toHaveKey('Params')
        ->and($frag['Params']['type'])->toBe('object');
});

it('the schema fragment is keyed by whatever paramsArg name the caller passes', function () {
    $frag = ParameterizedSql::inputSchemaFragment('params');

    expect($frag)->toHaveKey('params')
        ->and($frag)->not->toHaveKey('Params');
});

it('the schema fragment validates real args through opis/json-schema', function () {
    $frag = ParameterizedSql::inputSchemaFragment('params');
    $schema = [
        'type'                 => 'object',
        'properties'           => ['params' => $frag['params']],
        'additionalProperties' => false,
    ];
    $validator = new \Vested\Connect\Sdk\Schema\JsonSchemaValidator($schema);

    // A scalar-and-list params object validates cleanly...
    expect($validator->validate(['params' => ['from' => '2026-01-01', 'locs' => ['A', 'B']]]))->toBe([]);

    // ...but the exact shape normalise() would refuse is rejected at the
    // schema layer too, before a connector's tool handler is ever reached.
    expect($validator->validate(['params' => ['x' => ['a' => 1]]]))->not->toBe([]);
});

it('normalises real json_decode output, not a hand-built array — including the injection value on the wire', function () {
    // Every other test in this file builds its input as a PHP array
    // literal, which proves nothing about the actual wire shape:
    // ToolDispatcher decodes call args with
    // json_decode($req->getArgsJson(), associative: true)
    // (Tool/ToolDispatcher.php:68), and a connector handler pulls its own
    // params argument out of that decoded array before calling normalise().
    // This test starts from a JSON STRING of a realistic args payload — a
    // string, a number, and a list, under "params" — decodes it the SAME
    // way, and proves the injection value survives on the real path rather
    // than only against a synthetic array.
    $json = '{"sql":"SELECT 1","params":{"from":"2026-01-01\'; DROP TABLE x --","limit":5,"locs":["A","B"]}}';
    $args = json_decode($json, associative: true);

    $out = ParameterizedSql::normalise($args['params']);

    expect($out['from'])->toBe("2026-01-01'; DROP TABLE x --")
        ->and($out['limit'])->toBe(5)
        ->and($out['locs'])->toBe('["A","B"]');
});

it('pins {} vs [] — an empty JSON object is indistinguishable from an empty array after an associative decode, and BOTH are treated as an empty list', function () {
    // json_decode('{}', associative: true) and json_decode('[]', associative:
    // true) both produce PHP [], and array_is_list([]) is true either way —
    // the two are genuinely indistinguishable once decoded this way. This is
    // NOT a bug: refusing an empty array would break a real, legitimate value
    // (an empty IN-list) in order to chase a shape that cannot be told apart
    // from it after decoding. Pinned here so nobody "fixes" it into a
    // refusal later.
    $fromEmptyObject = json_decode('{"locs":{}}', associative: true);
    $fromEmptyArray  = json_decode('{"locs":[]}', associative: true);

    expect($fromEmptyObject['locs'])->toBe([])
        ->and($fromEmptyArray['locs'])->toBe([])
        ->and(ParameterizedSql::normalise($fromEmptyObject)['locs'])->toBe('[]')
        ->and(ParameterizedSql::normalise($fromEmptyArray)['locs'])->toBe('[]');
});
