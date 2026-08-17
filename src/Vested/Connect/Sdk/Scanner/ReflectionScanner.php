<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Scanner;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Vested\Connect\Sdk\Agent\AgentBuilder;
use Vested\Connect\Sdk\Attribute\Agent;
use Vested\Connect\Sdk\Attribute\Instruction;
use Vested\Connect\Sdk\Attribute\Model;
use Vested\Connect\Sdk\Attribute\Tool;
use Vested\Connect\Sdk\Exception\ConfigException;

/**
 * Discovers attribute-decorated classes under a namespace+directory and
 * produces AgentBuilders ready for ConnectorApp::build().
 *
 * Uses PSR-4 namespace→dir mapping (no global class scanning).
 */
final class ReflectionScanner
{
    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /**
     * @param  array<string, AgentBuilder>  $existingAgents  Agents already registered in ConnectorApp;
     *                                                        allows tools scanned in a second scanNamespace()
     *                                                        call to reference agents discovered in a prior call.
     */
    public function scan(string $namespace, string $dir, array $existingAgents = []): ScannerResult
    {
        if (! is_dir($dir)) {
            throw new ConfigException("scan dir does not exist: {$dir}");
        }

        $classes = $this->discoverClasses($namespace, $dir);

        /** @var array<string, AgentBuilder> $agentsByKey  (starts from existing agents so tool refs resolve) */
        $agentsByKey = $existingAgents;
        /** @var array<string, AgentBuilder> $newAgents  only agents discovered in this scan */
        $newAgents = [];

        // First pass: agents.
        foreach ($classes as $fqcn) {
            $rc = new ReflectionClass($fqcn);
            $agentAttrs = $rc->getAttributes(Agent::class);
            if (empty($agentAttrs)) {
                continue;
            }
            /** @var Agent $a */
            $a = $agentAttrs[0]->newInstance();

            $b = new AgentBuilder($a->key);
            $b->name($a->name)->description($a->description)->status($a->status);

            $modelAttrs = $rc->getAttributes(Model::class);
            if (! empty($modelAttrs)) {
                /** @var Model $m */
                $m = $modelAttrs[0]->newInstance();
                $b->withModel($m->provider, $m->name, $m->config);
            }

            foreach ($rc->getAttributes(Instruction::class) as $iAttr) {
                /** @var Instruction $i */
                $i = $iAttr->newInstance();
                $b->withInstruction($i->body, type: $i->type, position: $i->position, format: $i->format);
            }

            if (isset($agentsByKey[$a->key])) {
                throw new ConfigException("duplicate agent key '{$a->key}' from scanner");
            }
            $agentsByKey[$a->key] = $b;
            $newAgents[$a->key]   = $b;
        }

        // Second pass: tools (must reference an agent declared above or passed in as existing).
        foreach ($classes as $fqcn) {
            $rc = new ReflectionClass($fqcn);
            $toolAttrs = $rc->getAttributes(Tool::class);
            if (empty($toolAttrs)) {
                continue;
            }
            /** @var Tool $t */
            $t = $toolAttrs[0]->newInstance();

            $targets = $this->resolveAgentTargets($t, array_keys($agentsByKey), $fqcn);

            $inputSchema  = $this->loadSchema($t->inputSchema,  $t->inputSchemaFile,  $fqcn, 'input_schema');
            $outputSchema = $this->loadSchema($t->outputSchema, $t->outputSchemaFile, $fqcn, 'output_schema');

            // ONE handler instance across every bound agent. ToolRegistry keys
            // by tool_key and refuses two DIFFERENT handlers under one key, so
            // instantiating per agent here would turn a legitimate shared tool
            // into a duplicate-key error.
            $handler = $this->instantiateHandler($fqcn);

            foreach ($targets as $agentKey) {
                $agentsByKey[$agentKey]->withTool(
                    key: $t->key,
                    name: $t->name,
                    description: $t->description,
                    inputSchema: $inputSchema,
                    outputSchema: $outputSchema,
                    handler: $handler,
                    deadlineMs: $t->deadlineMs,
                    maxResultBytes: $t->maxResultBytes,
                    sensitivity: $t->sensitivity,
                );
            }
        }

        return new ScannerResult(array_values($newAgents));
    }

    /**
     * Which agents a #[Tool] binds to.
     *
     * A plain string is the historical single binding. A list binds one
     * declaration to several agents, and '*' means every declared agent —
     * resolved here, at scan time, so an agent added later picks the tool up.
     *
     * Everything that cannot be meant is refused HERE rather than downstream:
     * an unknown agent key would otherwise bind the tool to nothing at all,
     * which no caller would ever notice.
     *
     * @param  list<string>  $declaredAgentKeys
     * @return list<string>
     */
    private function resolveAgentTargets(Tool $t, array $declaredAgentKeys, string $fqcn): array
    {
        $requested = is_array($t->agentKey) ? array_values($t->agentKey) : [$t->agentKey];

        if ($requested === []) {
            throw new ConfigException(
                "tool '{$t->key}' declares an empty agentKey (declared on {$fqcn}). "
                . 'Name at least one agent, or "*" for every agent this connector declares.'
            );
        }

        $hasStar = in_array('*', $requested, true);

        if ($hasStar && count($requested) > 1) {
            $explicit = implode(', ', array_filter($requested, fn ($k) => $k !== '*'));
            throw new ConfigException(
                "tool '{$t->key}' combines \"*\" with explicit agent keys ({$explicit}) "
                . "(declared on {$fqcn}). \"*\" already means every agent; drop one or the other."
            );
        }

        if ($hasStar) {
            if ($declaredAgentKeys === []) {
                throw new ConfigException(
                    "tool '{$t->key}' declares agentKey \"*\" but this connector declares "
                    . "no agents (declared on {$fqcn})."
                );
            }

            return $declaredAgentKeys;
        }

        foreach ($requested as $key) {
            if (! in_array($key, $declaredAgentKeys, true)) {
                throw new ConfigException(
                    "tool '{$t->key}' references unknown agent '{$key}' (declared on {$fqcn})"
                );
            }
        }

        return $requested;
    }

    /**
     * @param  array<string,mixed>|null  $inline
     * @return array<string,mixed>
     */
    private function loadSchema(?array $inline, string $file, string $fqcn, string $which): array
    {
        if ($inline !== null) {
            return $inline;
        }
        if ($file === '') {
            throw new ConfigException("{$which} not provided on tool {$fqcn} (need inline schema OR schemaFile)");
        }
        if (! is_file($file)) {
            throw new ConfigException("{$which} file not found for tool {$fqcn}: {$file}");
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            throw new ConfigException("{$which} file is not valid JSON for tool {$fqcn}: {$file}");
        }
        return $decoded;
    }

    /** @return \Vested\Connect\Sdk\Tool\ToolHandler */
    private function instantiateHandler(string $fqcn)
    {
        if ($this->container !== null && $this->container->has($fqcn)) {
            $inst = $this->container->get($fqcn);
        } else {
            $inst = new $fqcn();
        }
        if (! $inst instanceof \Vested\Connect\Sdk\Tool\ToolHandler) {
            throw new ConfigException("tool class {$fqcn} must implement Vested\\Connect\\Sdk\\Tool\\ToolHandler");
        }
        return $inst;
    }

    /** @return list<class-string> */
    private function discoverClasses(string $namespace, string $dir): array
    {
        $namespace = rtrim($namespace, '\\');
        $classes = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1, -4); // strip dir/ and .php
            $sub = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            $fqcn = $namespace . '\\' . $sub;
            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }
        return $classes;
    }
}
