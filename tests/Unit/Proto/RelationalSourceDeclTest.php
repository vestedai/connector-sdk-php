<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tests\Unit\Proto;

// ---------------------------------------------------------------------------
// Proves RelationalSourceDecl exists in the PHP-vendored proto (Task 4:
// vested-ai-sdks/php/proto/vested/v1/connector_hub.proto, regenerated via
// `buf generate`) and that a Register carrying one round-trips through
// wire-format protobuf with every field intact.
//
// Field numbers are wire-format contract with the canonical proto
// (proto/vested/v1/connector_hub.proto): credential_schema = 3,
// relational_source = 4, and within RelationalSourceDecl itself:
// engine = 1, describe_tool = 2, query_tool = 3, sql_arg = 4,
// fingerprint = 5. This test only exercises correctness of a same-schema
// round trip; it does NOT by itself prove the numbering matches canonical —
// see the mutation drill in task-4-report.md for that.
//
// Mirrors vested-ai-sdks/dotnet/tests/VestedAI.ConnectorSdk.Tests/
// RelationalSourceDeclTests.cs (Task 1's equivalent for the .NET SDK).
// ---------------------------------------------------------------------------

use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RelationalSourceDecl;

it('round-trips every RelationalSourceDecl field through a Register on the wire', function () {
    $original = new Register([
        'baseline_fingerprint' => 'fp-abc123',
        'relational_source' => new RelationalSourceDecl([
            'engine' => 'sqlserver',
            'describe_tool' => 'erp.describe_schema',
            'query_tool' => 'erp.query_sql',
            'sql_arg' => 'Sql',
            'fingerprint' => 'catalog-hash-9f8e7d',
        ]),
    ]);

    $bytes = $original->serializeToString();

    $parsed = new Register();
    $parsed->mergeFromString($bytes);

    expect($parsed->getBaselineFingerprint())->toBe('fp-abc123');
    expect($parsed->hasRelationalSource())->toBeTrue();

    $source = $parsed->getRelationalSource();
    assert($source !== null);

    expect($source->getEngine())->toBe('sqlserver');
    expect($source->getDescribeTool())->toBe('erp.describe_schema');
    expect($source->getQueryTool())->toBe('erp.query_sql');
    expect($source->getSqlArg())->toBe('Sql');
    expect($source->getFingerprint())->toBe('catalog-hash-9f8e7d');
});

it('leaves relational_source absent after round-tripping a Register that never set it', function () {
    // Absent = "this connector fronts no relational database" per the proto
    // comment — the same "declare nothing, stay untouched" contract as
    // credential_schema. A caller must be able to branch on presence with
    // hasRelationalSource(), exactly as existing code already does for
    // hasCredentialSchema().
    $original = new Register(['baseline_fingerprint' => 'fp-none']);

    $parsed = new Register();
    $parsed->mergeFromString($original->serializeToString());

    expect($parsed->hasRelationalSource())->toBeFalse();
    expect($parsed->getRelationalSource())->toBeNull();
});

it('round-trips a standalone RelationalSourceDecl independent of Register', function () {
    // Also exercise the message type on its own, independent of Register,
    // since Task 5 will construct it directly (e.g. from a connector's
    // declared decl) before ever touching a Register.
    $original = new RelationalSourceDecl([
        'engine' => 'mysql',
        'describe_tool' => 'd.describe',
        'query_tool' => 'd.query',
        'sql_arg' => 'sql',
        'fingerprint' => 'f-1',
    ]);

    $parsed = new RelationalSourceDecl();
    $parsed->mergeFromString($original->serializeToString());

    expect($parsed->getEngine())->toBe($original->getEngine());
    expect($parsed->getDescribeTool())->toBe($original->getDescribeTool());
    expect($parsed->getQueryTool())->toBe($original->getQueryTool());
    expect($parsed->getSqlArg())->toBe($original->getSqlArg());
    expect($parsed->getFingerprint())->toBe($original->getFingerprint());
});
