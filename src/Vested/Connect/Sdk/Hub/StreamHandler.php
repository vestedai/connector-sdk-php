<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Hub;

use Google\Protobuf\Timestamp;
use Psr\Log\LoggerInterface;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\AgentDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\CredentialFieldDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\CredentialSchemaDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Heartbeat;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Hello;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\InstructionDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ModelDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RelationalSourceDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ResultKind;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RegisterAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolDecl;
use Vested\Connect\Sdk\Schema\CatalogFingerprint;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;

/**
 * Stateless helpers for building outbound ConnectorMsg envelopes and
 * formatting inbound HubMsg payloads (specifically RegisterAck issues).
 */
final class StreamHandler
{
    public static function buildHello(string $sdkLanguage, string $sdkVersion, string $workerId): ConnectorMsg
    {
        $hello = new Hello();
        $hello->setSdkLanguage($sdkLanguage);
        $hello->setSdkVersion($sdkVersion);
        $hello->setWorkerId($workerId);
        $msg = new ConnectorMsg();
        $msg->setHello($hello);
        return $msg;
    }

    public static function buildRegister(ConnectorApp $app): ConnectorMsg
    {
        $reg = new Register();
        $reg->setBaselineFingerprint($app->agents()->fingerprint());

        $agents = [];
        foreach ($app->agents()->declarations() as $decl) {
            $a = new AgentDecl();
            $a->setKey($decl['key']);
            $a->setName($decl['name']);
            $a->setDescription($decl['description'] ?? '');
            $a->setStatus($decl['status'] ?? 'active');

            $m = new ModelDecl();
            $m->setProvider($decl['model']['provider'] ?? '');
            $m->setName($decl['model']['name'] ?? '');
            if (!empty($decl['model']['config'])) {
                $configStruct = new \Google\Protobuf\Struct();
                $fields = $configStruct->getFields();
                foreach (($decl['model']['config'] ?? []) as $k => $v) {
                    $fields[(string) $k] = self::toProtoValue($v);
                }
                $m->setConfig($configStruct);
            }
            $a->setModel($m);

            $instrs = [];
            foreach (($decl['instructions'] ?? []) as $i) {
                $id = new InstructionDecl();
                $id->setType($i['type']);
                $id->setFormat($i['format']);
                $id->setBody($i['body']);
                $id->setPosition($i['position']);
                $instrs[] = $id;
            }
            $a->setInstructions($instrs);

            $tools = [];
            foreach (($decl['tools'] ?? []) as $t) {
                $td = new ToolDecl();
                $td->setKey($t['key']);
                $td->setName($t['name']);
                $td->setDescription($t['description'] ?? '');
                $inputJson = json_encode($t['input_schema_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $outputJson = json_encode($t['output_schema_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $td->setInputSchemaJson($inputJson);
                $td->setOutputSchemaJson($outputJson);
                $td->setDefaultDeadlineMs($t['default_deadline_ms'] ?? 30000);
                $td->setMaxResultBytes($t['max_result_bytes'] ?? 1048576);
                $td->setSensitivity($t['sensitivity'] ?? '');
                $td->setResultKind(
                    (($t['result_kind'] ?? 'single') === 'rowset')
                        ? ResultKind::RESULT_KIND_ROWSET
                        : ResultKind::RESULT_KIND_SINGLE
                );
                $tools[] = $td;
            }
            $a->setTools($tools);

            $agents[] = $a;
        }
        $reg->setAgents($agents);

        // Absent when the connector declares no per-user auth — that absence is
        // what tells the platform to hide it from the credential UI and never
        // gate its tools.
        $credential = $app->credentialSchemaDeclaration();
        if ($credential !== null) {
            $reg->setCredentialSchema(self::credentialSchemaDecl($credential));
        }

        // Same contract, one field over: absent when the connector fronts no
        // relational database, which is what tells the platform never to
        // extract a schema for it or gate a query tool it does not have.
        $relational = $app->relationalSourceDeclaration();
        if ($relational !== null) {
            $reg->setRelationalSource(self::relationalSourceDecl(
                $relational,
                $app->relationalSchemaProvider(),
                $app->logger(),
            ));
        }

        $msg = new ConnectorMsg();
        $msg->setRegister($reg);
        return $msg;
    }

    /**
     * @param  array{kind: string, title: string, help_text: string, fields: list<array{key: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>}  $decl
     */
    private static function credentialSchemaDecl(array $decl): CredentialSchemaDecl
    {
        $d = new CredentialSchemaDecl();
        $d->setKind($decl['kind']);
        $d->setTitle($decl['title']);
        $d->setHelpText($decl['help_text']);

        $fields = [];
        foreach ($decl['fields'] as $f) {
            $fd = new CredentialFieldDecl();
            $fd->setKey($f['key']);
            $fd->setLabel($f['label']);
            $fd->setType($f['type']);
            $fd->setRequired($f['required']);
            $fd->setPlaceholder($f['placeholder']);
            $fd->setOptions($f['options']);
            $fields[] = $fd;
        }
        $d->setFields($fields);

        return $d;
    }

    /**
     * The fingerprint is read from the provider HERE, as the message is built —
     * never captured when the provider was registered, where it would be stale
     * by the time it is sent.
     *
     * @param  array{engine: string, describe_tool: string, query_tool: string, sql_arg: string, default_scope: string, scopes: list<string>}  $decl
     */
    private static function relationalSourceDecl(
        array $decl,
        ?RelationalSchemaProvider $provider,
        LoggerInterface $logger,
    ): RelationalSourceDecl {
        $d = new RelationalSourceDecl();
        $d->setEngine($decl['engine']);
        $d->setDescribeTool($decl['describe_tool']);
        $d->setQueryTool($decl['query_tool']);
        $d->setSqlArg($decl['sql_arg']);
        // Already validated at bootstrap (ConnectorApp::validateRelationalSourceScopes):
        // a multi-scope source cannot reach here without a default_scope that is
        // itself one of scopes, so this is a straight pass-through onto the wire.
        $d->setScopes($decl['scopes'] ?? []);
        $d->setDefaultScope((string) ($decl['default_scope'] ?? ''));
        // proto3 would default this to '' anyway. Stated because the empty
        // string is not an unset field here, it is the failure contract: the
        // line below either overwrites it or deliberately leaves it.
        $d->setFingerprint('');

        // ConnectorApp derives the declaration FROM the provider, so a
        // declaration without one cannot happen — but losing the whole
        // declaration to a TypeError would be exactly the silent disablement
        // CatalogFingerprint exists to prevent.
        if ($provider === null) {
            return $d;
        }

        $d->setFingerprint(CatalogFingerprint::read($provider, $logger, $decl['query_tool']));

        return $d;
    }

    public static function buildHeartbeat(): ConnectorMsg
    {
        $hb = new Heartbeat();
        $ts = new Timestamp();
        $ts->setSeconds(time());
        $hb->setAt($ts);
        $msg = new ConnectorMsg();
        $msg->setHeartbeat($hb);
        return $msg;
    }

    /** @return list<string> */
    public static function formatRegisterIssues(RegisterAck $ack): array
    {
        $out = [];
        foreach ($ack->getIssues() as $i) {
            $out[] = sprintf('[%s] %s: %s', $i->getCode(), $i->getPath(), $i->getMessage());
        }
        return $out;
    }

    private static function toProtoValue(mixed $v): \Google\Protobuf\Value
    {
        $pv = new \Google\Protobuf\Value();
        if (is_string($v))      { $pv->setStringValue($v); }
        elseif (is_int($v) || is_float($v)) { $pv->setNumberValue((float) $v); }
        elseif (is_bool($v))    { $pv->setBoolValue($v); }
        elseif ($v === null)    { $pv->setNullValue(\Google\Protobuf\NullValue::NULL_VALUE); }
        elseif (is_array($v))   {
            $struct = new \Google\Protobuf\Struct();
            $fields = $struct->getFields();
            foreach ($v as $kk => $vv) {
                $fields[(string) $kk] = self::toProtoValue($vv);
            }
            $pv->setStructValue($struct);
        }
        return $pv;
    }
}
