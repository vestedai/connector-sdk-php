<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Credential;

use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\CredentialOpRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\CredentialOpResponse;

/**
 * Worker-side dispatcher for credential lifecycle ops.
 *
 * It opens the sealed envelope and hands the handler PLAINTEXT. That is the
 * point: the AAD identity check lives in CredentialOpener, inside this call
 * path, so a connector author cannot accidentally skip the one check that makes
 * per-user auth mean anything.
 *
 * Never throws — every failure becomes a CredentialOpResponse{ok:false}, since
 * an unanswered op would leave the platform waiting out its deadline.
 */
final class CredentialOpDispatcher
{
    public function __construct(
        private readonly CredentialOpener $opener,
        private readonly ?UserCredentialHandler $handler,
        private readonly string $connectorId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(CredentialOpRequest $req): CredentialOpResponse
    {
        $resp = new CredentialOpResponse(['op_id' => $req->getOpId()]);

        if ($this->handler === null) {
            $resp->setOk(false);
            $resp->setError('This integration does not accept per-user credentials.');

            return $resp;
        }

        $envelope = json_decode($req->getEnvelopeJson() ?: '', associative: true);
        if (! is_array($envelope)) {
            $resp->setOk(false);
            $resp->setError('The stored credential is unreadable. Please enter it again.');

            return $resp;
        }

        try {
            $credential = $this->opener->open($envelope, $this->connectorId, $req->getUserId());
        } catch (CredentialException $e) {
            // The exception text can name key fingerprints and internals, so it
            // is logged but never returned. An identity mismatch in particular
            // is a security event, not a user-fixable typo.
            $this->logger->warning('credential envelope could not be opened', [
                'op_id'   => $req->getOpId(),
                'user_id' => $req->getUserId(),
                'reason'  => $e->getMessage(),
            ]);
            $resp->setOk(false);
            $resp->setError('The stored credential could not be read by this integration. Please enter it again.');

            return $resp;
        }

        $ctx = new CredentialContext(
            opId: $req->getOpId(),
            userId: $req->getUserId(),
            userEmail: $req->getUserEmail(),
            logger: $this->logger,
            employeeNo: $req->getEmployeeNo(),
            erpIdentifier: $req->getErpIdentifier(),
        );

        try {
            if ($req->getOp() === 'revoke') {
                $this->handler->revoke($ctx, $credential);
                $resp->setOk(true);

                return $resp;
            }

            $verdict = $this->handler->validate($ctx, $credential);
            $resp->setOk($verdict->ok);
            $resp->setError($verdict->error);
            if ($verdict->display !== []) {
                $resp->setDisplay($this->toStruct($verdict->display));
            }
        } catch (Throwable $e) {
            $this->logger->error('credential handler threw', [
                'op_id' => $req->getOpId(),
                'op'    => $req->getOp(),
                'error' => $e->getMessage(),
            ]);
            $resp->setOk(false);
            $resp->setError('The integration could not check these credentials right now.');
        }

        return $resp;
    }

    /** @param array<string, string> $display */
    private function toStruct(array $display): Struct
    {
        $fields = [];
        foreach ($display as $key => $value) {
            $fields[(string) $key] = (new Value)->setStringValue((string) $value);
        }

        return (new Struct)->setFields($fields);
    }
}
