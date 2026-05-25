<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Tool;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallRequest;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ToolCallResponse;
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
            invocationId:   $req->getInvocationId(),
            organizationId: $req->getOrganizationId(),
            userId:         $req->getUserId(),
            userEmail:      $req->getUserEmail(),
            conversationId: $req->getConversationId(),
            agentKey:       $req->getAgentKey(),
            toolKey:        $key,
            deadlineMs:     $req->getDeadlineMs(),
            logger:         $this->logger,
            invokedAt:      new DateTimeImmutable(),
        );

        try {
            $handler = $this->registry->resolve($key);
            $result = $handler instanceof Closure
                ? $handler($args, $ctx)
                : $handler->handle($args, $ctx);
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
}
