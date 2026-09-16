<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Connection;

use Google\Protobuf\NullValue;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Vested\Connect\Sdk\ConnectorApp;
use Vested\Connect\Sdk\Credential\CredentialException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionOpResponse;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectionResource as ProtoConnectionResource;

/**
 * Answers the hub's validate and list_resources ops (spec §3.4).
 *
 * ALWAYS answers — an unanswered op costs the tenant admin the whole deadline
 * staring at a spinner. Every failure is one fixed, user-facing sentence: the
 * error field is shown in the platform UI, and exception messages from provider
 * SDKs routinely echo request bodies.
 */
final class ConnectionOpDispatcher
{
    public function __construct(
        private readonly PlatformConnectionOpener $opener,
        private readonly ConnectorApp $app,
        private readonly \Closure $connectorId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(ConnectionOpRequest $req): ConnectionOpResponse
    {
        $resp = new ConnectionOpResponse(['op_id' => $req->getOpId()]);

        $handler = $this->app->connectionProfileHandler($req->getProfile());
        if ($handler === null) {
            return $this->fail($resp, 'This integration does not provide that connection.');
        }

        if (! in_array($req->getOp(), ['validate', 'list_resources'], true)) {
            return $this->fail($resp, 'This integration does not support that connection operation.');
        }

        $envelope = json_decode($req->getEnvelopeJson() ?: '', true);
        try {
            if (! is_array($envelope)) {
                throw CredentialException::decryptFailed('connection envelope is not a JSON object');
            }
            $credential = $this->opener->open($envelope, ($this->connectorId)(), $req->getOrganizationId(), $req->getTenantConnectionId());
        } catch (CredentialException $e) {
            $this->logger->warning('connection envelope could not be opened', [
                'op_id' => $req->getOpId(), 'organization_id' => $req->getOrganizationId(),
                'profile' => $req->getProfile(), 'reason' => $e->getMessage(),
            ]);

            return $this->fail($resp, 'This integration could not read the connection. Please reconnect it.');
        }

        $ctx = new ConnectionOpContext(
            opId: $req->getOpId(),
            organizationId: $req->getOrganizationId(),
            profile: $req->getProfile(),
            tenantConnectionId: $req->getTenantConnectionId(),
            deadlineMs: $req->getDeadlineMs(),
            logger: $this->logger,
        );

        try {
            if ($req->getOp() === 'list_resources') {
                $resp->setOk(true);
                $resp->setResources(array_map(
                    fn (ConnectionResource $r) => new ProtoConnectionResource([
                        'id' => $r->id, 'label' => $r->label, 'meta' => $this->toStruct($r->meta),
                    ]),
                    $handler->listResources($ctx, $credential),
                ));

                return $resp;
            }

            $verdict = $handler->validate($ctx, $credential);
            $resp->setOk($verdict->ok);
            $resp->setError($verdict->error);
            if ($verdict->display !== []) {
                $resp->setDisplay($this->toStruct($verdict->display));
            }
        } catch (Throwable $e) {
            $this->logger->error('connection profile handler threw', [
                'op_id' => $req->getOpId(), 'op' => $req->getOp(), 'profile' => $req->getProfile(),
                'exception_class' => $e::class,
            ]);

            return $this->fail(new ConnectionOpResponse(['op_id' => $req->getOpId()]), 'The integration could not check this connection right now.');
        }

        return $resp;
    }

    private function fail(ConnectionOpResponse $resp, string $sentence): ConnectionOpResponse
    {
        $resp->setOk(false);
        $resp->setError($sentence);

        return $resp;
    }

    /**
     * Type-dispatches rather than stringifying: $meta is
     * array<string, scalar|Stringable|null> (ConnectionResource) or array<string, string>
     * (CredentialValidation::$display), and a blind (string) cast turns `true`
     * into `"1"` and turns both `false` and `null` into `""` — indistinguishable
     * in the tenant's admin UI, and silently wrong.
     *
     * @param array<string, mixed> $values
     */
    private function toStruct(array $values): Struct
    {
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[(string) $key] = $this->toValue($value);
        }

        return (new Struct())->setFields($fields);
    }

    /**
     * The default branch below only ever runs `(string) $value` on something
     * that is actually string-shaped. Casting an array or a non-Stringable
     * object to string does not throw — it emits an E_WARNING and, for an
     * array, silently produces the literal four characters "Array" — which
     * would slip straight past dispatch()'s catch (Throwable) and reach the
     * tenant's admin UI as if it were a real fact. A handler that returns
     * such a value is violating ConnectionResource's documented
     * array<string, scalar|Stringable|null> contract; PHP does not enforce that, so this
     * guard does, routing the mistake through the same fixed-sentence failure
     * path as a thrown handler exception.
     */
    private function toValue(mixed $value): Value
    {
        return match (true) {
            $value === null                                     => (new Value())->setNullValue(NullValue::NULL_VALUE),
            is_bool($value)                                      => (new Value())->setBoolValue($value),
            is_int($value) || is_float($value)                   => (new Value())->setNumberValue((float) $value),
            is_string($value) || $value instanceof \Stringable   => (new Value())->setStringValue((string) $value),
            default => throw new \TypeError(sprintf(
                "a connection profile handler's meta/display value must be scalar, null or Stringable; got %s",
                get_debug_type($value),
            )),
        };
    }
}
