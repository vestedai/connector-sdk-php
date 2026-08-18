<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\SchemaContext as ProtoSchemaContext;
use Vested\Connect\Sdk\Credential\CredentialOpener;
use Vested\Connect\Sdk\Credential\CredentialResolver;
use Vested\Connect\Sdk\Observability\Tracing;
use Vested\Connect\Sdk\Schema\JsonSchemaValidator;

/**
 * Worker-side dispatcher. Takes a ToolCallRequest, validates args,
 * invokes the handler, validates the result, returns a ToolCallResponse.
 *
 * Never throws — every failure (missing handler, schema rejection,
 * handler exception) becomes a ToolCallResponse{error: "..."}.
 */
final class ToolDispatcher
{
    /** @var array<string, JsonSchemaValidator> */
    private array $inputValidators = [];
    /** @var array<string, JsonSchemaValidator> */
    private array $outputValidators = [];

    /**
     * @param  array<string, array{input_schema: array<string,mixed>, output_schema: array<string,mixed>}>  $toolMeta
     */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly array $toolMeta,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?Tracing $tracing = null,
        /** Null for connectors that declare no credential schema. */
        private readonly ?CredentialOpener $credentialOpener = null,
        /**
         * The hub's connector id, part of the envelope AAD. Resolved lazily:
         * it arrives at HelloAck, after this object is constructed.
         *
         * @var (\Closure(): string)|null
         */
        private readonly ?\Closure $connectorId = null,
    ) {
        foreach ($this->toolMeta as $key => $meta) {
            $this->inputValidators[$key]  = new JsonSchemaValidator($meta['input_schema']);
            $this->outputValidators[$key] = new JsonSchemaValidator($meta['output_schema']);
        }
    }

    public function dispatch(ToolCallRequest $req): ToolCallResponse
    {
        $startMs = (int) (microtime(true) * 1000);
        $resp = new ToolCallResponse(['invocation_id' => $req->getInvocationId()]);

        $key = $req->getToolKey();
        if (! $this->registry->has($key) || ! isset($this->inputValidators[$key])) {
            $resp->setError("unknown tool_key '{$key}'");
            return $resp;
        }

        $args = json_decode($req->getArgsJson() ?: '{}', associative: true);
        if (! is_array($args)) {
            $resp->setError('args_json is not a JSON object');
            return $resp;
        }

        $inputErrors = $this->inputValidators[$key]->validate($args);
        if (! empty($inputErrors)) {
            $resp->setError('input_schema validation failed: ' . implode('; ', $inputErrors));
            return $resp;
        }

        $ctx = new ToolContext(
            invocationId:              $req->getInvocationId(),
            organizationId:            $req->getOrganizationId(),
            userId:                    $req->getUserId(),
            userEmail:                 $req->getUserEmail(),
            conversationId:            $req->getConversationId(),
            agentKey:                  $req->getAgentKey(),
            toolKey:                   $key,
            deadlineMs:                $req->getDeadlineMs(),
            logger:                    $this->logger,
            invokedAt:                 new DateTimeImmutable(),
            employeeNo:                $req->getEmployeeNo(),
            erpIdentifier:             $req->getErpIdentifier(),
            erpDepartmentIdentifiers:  iterator_to_array($req->getErpDepartmentIdentifiers()),
            // Lazy: most tools never read the credential, and one that doesn't
            // ask should neither pay for a decrypt nor fail because of one.
            credentials: new CredentialResolver(
                opener:       $this->credentialOpener,
                envelopeJson: $req->getCredentialEnvelopeJson(),
                connectorId:  $this->connectorId === null ? '' : ($this->connectorId)(),
                userId:       $req->getUserId(),
            ),
            schemaContext: self::mapSchemaContext($req->getSchemaContext()),
        );

        $tracing = $this->tracing ?? new Tracing(null);
        try {
            $handler = $this->registry->resolve($key);

            if ($handler instanceof PaginatedToolHandler) {
                $cursorTok = $req->getCursor();
                $cursor = new DatasetCursor($cursorTok !== '' ? $cursorTok : null, $req->getPageSize());
                $page = $handler->fetchPage($args, $cursor, $ctx);
                $resp->setResultJson(json_encode(['rows' => $page->rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $resp->setNextCursor($page->nextCursor ?? '');
                $resp->setTotalRows($page->total ?? 0);
                return $resp;
            }

            $result = $tracing->span(
                'connector.tool_handler',
                fn () => $handler instanceof Closure ? $handler($args, $ctx) : $handler->handle($args, $ctx),
                ['tool.key' => $key, 'invocation.id' => $req->getInvocationId()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('tool handler crashed', [
                'tool_key' => $key, 'invocation_id' => $req->getInvocationId(),
                'exception' => $e->getMessage(),
            ]);
            $resp->setError(substr($e->getMessage(), 0, 1024));
            $resp->setDurationMs(max(0, (int) (microtime(true) * 1000) - $startMs));
            return $resp;
        }

        if (! is_array($result)) {
            $resp->setError('handler must return an array');
            return $resp;
        }

        $outputErrors = $this->outputValidators[$key]->validate($result);
        if (! empty($outputErrors)) {
            $resp->setError('output_schema validation failed: ' . implode('; ', $outputErrors));
            return $resp;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $resp->setError('result not JSON-serializable');
            return $resp;
        }
        $resp->setResultJson($encoded);
        $resp->setDurationMs(max(0, (int) (microtime(true) * 1000) - $startMs));
        return $resp;
    }

    /**
     * Maps the wire message to the SDK value object, an absent message to
     * null.
     *
     * The absent case is the common one: it fires for every call except a
     * governed query tool the core's gate reached a decision on. Preserving
     * that absence as null — rather than coercing it into an empty
     * {@see SchemaContext} — is exactly what keeps
     * {@see ToolContext::$schemaContext} truthful to its docblock.
     */
    private static function mapSchemaContext(?ProtoSchemaContext $proto): ?SchemaContext
    {
        if ($proto === null) {
            return null;
        }

        $tables = [];
        foreach ($proto->getTables() as $t) {
            $tables[] = new SchemaContextTable(
                logicalName: $t->getLogicalName(),
                scope:       $t->getScope(),
                kind:        $t->getKind(),
                physical:    iterator_to_array($t->getPhysical()),
            );
        }

        return new SchemaContext(
            tables:   $tables,
            hasStar:  $proto->getHasStar(),
            gateMode: $proto->getGateMode(),
        );
    }
}
