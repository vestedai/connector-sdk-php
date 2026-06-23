<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Hub;

use Google\Protobuf\Timestamp;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\AgentDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Heartbeat;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Hello;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\InstructionDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ModelDecl;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\Register;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ResultKind;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\RegisterAck;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolDecl;

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

        $msg = new ConnectorMsg();
        $msg->setRegister($reg);
        return $msg;
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
