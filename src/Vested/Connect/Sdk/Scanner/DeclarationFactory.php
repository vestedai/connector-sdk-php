<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Scanner;

use ReflectionClass;
use Vested\Connect\Sdk\Attribute\CredentialField;
use Vested\Connect\Sdk\Attribute\CredentialSchema;
use Vested\Connect\Sdk\Attribute\RelationalSource;
use Vested\Connect\Sdk\Exception\ConfigException;
use Vested\Connect\Sdk\Schema\RelationalSchemaProvider;

/**
 * Turns the attributes on a connector's own classes into the wire-shape
 * declaration dicts the Register frame carries — the same snake_case array
 * shape AgentBuilder::toDeclaration() produces, so StreamHandler reads all
 * three the same way.
 *
 * Validation is strict and happens HERE, at startup, rather than at
 * registration. A malformed credential schema would otherwise surface as a
 * rejected Register or, worse, a form the user cannot complete; a malformed
 * relational source is worse still, because the core validates it for
 * non-emptiness and namespace only — a declaration it cannot act on registers
 * fine, extraction simply never happens and the query gate governs nothing.
 * That failure is invisible, so it must surface as a refusal to start.
 */
final class DeclarationFactory
{
    /**
     * Credential field keys, as the core validates them
     * (laravel/app/Services/Connectors/ConnectorRegistryService.php).
     */
    private const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * The credential form declared by a handler's class.
     *
     * Throws when the attribute is missing, exactly as the relational half
     * does. Registering a handler is not a passive act: a connector whose tools
     * are meant to run as the calling user, but which declares no
     * credential_schema, has NONE of its tools gated — so it keeps transacting
     * against the downstream system as whatever shared account it was
     * configured with, and that system's audit log names a robot instead of a
     * person. That is the misattribution per-user credentials exist to end, so
     * it is a refusal to start rather than a log line. (A warning would be
     * feeble anyway: ConnectorApp's logger defaults to NullLogger, so the only
     * mitigation would be silent by default.)
     *
     * @return array{
     *     kind: string,
     *     title: string,
     *     help_text: string,
     *     fields: list<array{key: string, label: string, type: string, required: bool, placeholder: string, options: list<string>}>
     * }
     */
    public static function credentialSchemaFrom(object $handler): array
    {
        $rc = new ReflectionClass($handler);
        $where = self::describe($handler);

        $schemaAttrs = $rc->getAttributes(CredentialSchema::class);
        $fieldAttrs  = $rc->getAttributes(CredentialField::class);

        if ($schemaAttrs === []) {
            if ($fieldAttrs !== []) {
                // Fields without a schema is a mistake, never a choice: the
                // fields describe a form whose heading and kind are missing, so
                // nothing can be rendered from them.
                throw new ConfigException(
                    "credential handler {$where} declares #[CredentialField]s but no "
                    . '#[CredentialSchema] — add #[CredentialSchema(kind: …, title: …)] to the class.'
                );
            }

            // The inheritance sentence is not padding: PHP does NOT inherit
            // class attributes, and getAttributes() reports only this class's
            // own. A handler extending an annotated base therefore declares
            // nothing while looking annotated at the call site.
            throw new ConfigException(
                "credential handler {$where} carries no #[CredentialSchema] attribute. The "
                . 'platform builds the user\'s credential form from it, and without one no user '
                . 'can save a credential and NONE of this connector\'s tools are gated — every '
                . 'call keeps running as the connector\'s own account. Add '
                . '#[CredentialSchema(kind: …, title: …)] plus one #[CredentialField] per field '
                . "to {$where} ITSELF: PHP does not inherit class attributes, so one on a parent "
                . 'class does not count.'
            );
        }

        /** @var CredentialSchema $schema */
        $schema = $schemaAttrs[0]->newInstance();

        $kind = $schema->kind === '' ? 'basic' : $schema->kind;
        if (! in_array($kind, CredentialSchema::KINDS, true)) {
            throw new ConfigException(
                "credential handler {$where} declares kind '{$kind}'; expected one of: "
                . implode(', ', CredentialSchema::KINDS) . '.'
            );
        }

        if (trim($schema->title) === '') {
            throw new ConfigException(
                "credential handler {$where} declares no title — it is the heading of the "
                . 'form the user fills in.'
            );
        }

        if ($fieldAttrs === []) {
            throw new ConfigException(
                "credential handler {$where} declares no #[CredentialField]s; the platform "
                . 'would render an empty form.'
            );
        }

        $fields = [];
        $seen   = [];
        foreach ($fieldAttrs as $attr) {
            /** @var CredentialField $f */
            $f = $attr->newInstance();

            if (trim($f->key) === '') {
                throw new ConfigException(
                    "credential handler {$where} declares a #[CredentialField] with no key."
                );
            }
            // Same pattern the core enforces (ConnectorRegistryService: "key
            // must match ^[a-z][a-z0-9_]*$"). Without this a camelCase key
            // builds fine here and is rejected at first connect instead —
            // loud, but a deploy later and in someone else's log.
            if (! preg_match(self::FIELD_KEY_PATTERN, $f->key)) {
                throw new ConfigException(
                    "credential field key '{$f->key}' on {$where} must match "
                    . '^[a-z][a-z0-9_]*$ — lower-case, starting with a letter. The core '
                    . 'rejects anything else when the connector registers.'
                );
            }
            if (isset($seen[$f->key])) {
                throw new ConfigException(
                    "credential handler {$where} declares duplicate field key '{$f->key}'."
                );
            }
            $seen[$f->key] = true;

            $type = $f->type === '' ? 'text' : $f->type;
            if (! in_array($type, CredentialField::TYPES, true)) {
                throw new ConfigException(
                    "credential field '{$f->key}' on {$where} declares type '{$type}'; "
                    . 'expected one of: ' . implode(', ', CredentialField::TYPES) . '.'
                );
            }
            if ($type === 'select' && $f->options === []) {
                throw new ConfigException(
                    "credential field '{$f->key}' on {$where} is a 'select' but declares no options."
                );
            }

            $fields[] = [
                'key'         => $f->key,
                'label'       => trim($f->label) === '' ? $f->key : $f->label,
                'type'        => $type,
                'required'    => $f->required,
                'placeholder' => $f->placeholder,
                'options'     => array_values($f->options),
            ];
        }

        return [
            'kind'      => $kind,
            'title'     => $schema->title,
            'help_text' => $schema->helpText,
            'fields'    => $fields,
        ];
    }

    /**
     * The relational source declared by a provider's class.
     *
     * Throws when the attribute is missing, exactly as the credential half
     * does: a provider that declares nothing would be registered and then never
     * used — the silent disablement this whole layer exists to close.
     *
     * @return array{engine: string, describe_tool: string, query_tool: string, sql_arg: string, params_arg: string, default_scope: string, scopes: list<string>}
     */
    public static function relationalSourceFrom(object $provider): array
    {
        $rc    = new ReflectionClass($provider);
        $where = self::describe($provider);

        $attrs = $rc->getAttributes(RelationalSource::class);
        if ($attrs === []) {
            throw new ConfigException(
                "relational schema provider {$where} carries no #[RelationalSource] attribute. "
                . 'The declaration names THIS connector\'s engine, tool keys and SQL argument, so it '
                . 'belongs on your class: '
                . '#[RelationalSource(engine: …, describeTool: …, queryTool: …, sqlArg: …)].'
            );
        }

        /** @var RelationalSource $source */
        $source = $attrs[0]->newInstance();

        // Each message names the blank field: an operator reading a startup
        // failure otherwise learns only that "something" was left out.
        //
        // `engine` is checked for emptiness ONLY — deliberately no allowlist,
        // unlike CredentialSchema::KINDS above. The core owns the set of
        // supported engines and already rejects an unknown one at first connect
        // (ConnectorRegistryService::validateRelationalSource → issue
        // "relational_engine_unsupported", whose message names the allowed set;
        // the Daemon prints every issue and exits 78). An SDK-side copy of that
        // list would refuse to start a valid connector until the SDK is
        // re-released — the SDK would become the thing blocking an engine the
        // platform already supports.
        self::requireValue($where, 'engine', $source->engine,
            'the database engine behind this connector, e.g. "mysql"');
        self::requireValue($where, 'describeTool', $source->describeTool,
            'the key of the tool that returns this source\'s canonical schema');
        self::requireValue($where, 'queryTool', $source->queryTool,
            'the key of the SQL tool the core\'s query gate governs');
        self::requireValue($where, 'sqlArg', $source->sqlArg,
            'which argument of queryTool carries the SQL text');

        // scopes() is the SDK's existing, already-public mechanism for "what
        // this source spans" (RelationalSchemaProvider::scopes()) — reused
        // here rather than adding a second, attribute-level list that could
        // drift from it. $provider is typed `object` above only so this
        // method's error-formatting stays generic; ConnectorApp's own
        // withRelationalSchemaProvider(RelationalSchemaProvider $provider)
        // signature is what actually guarantees the instanceof below.
        $scopes = $provider instanceof RelationalSchemaProvider ? $provider->scopes() : [];
        self::validateScopes($where, $scopes, $source->defaultScope);

        return [
            'engine'        => $source->engine,
            'describe_tool' => $source->describeTool,
            'query_tool'    => $source->queryTool,
            'sql_arg'       => $source->sqlArg,
            // Optional, unlike sqlArg above: no requireValue() call. Empty means
            // this source takes no bind parameters, and every existing connector
            // declares none.
            'params_arg'    => $source->paramsArg,
            // With exactly one scope the SOLE scope is the only place an
            // unqualified name can resolve, so it is emitted verbatim and the
            // attribute literal is ignored rather than trusted. That is not a
            // convenience: scopes() is runtime data (a DSN's database name),
            // defaultScope is a compile-time constant, and a connector whose
            // database is named differently per environment would otherwise
            // ship a default naming a scope THAT DEPLOYMENT DOES NOT HAVE —
            // which the core resolves unqualified names into, refusing every
            // one of them. Emitting the real scope keeps the declaration true
            // in every environment. See validateScopes() for the >1 case,
            // which is the one the operator must actually answer.
            'default_scope' => count($scopes) === 1 ? $scopes[0] : $source->defaultScope,
            'scopes'        => $scopes,
        ];
    }

    private static function requireValue(string $where, string $field, string $value, string $what): void
    {
        if (trim($value) === '') {
            throw new ConfigException(
                "relational schema provider {$where} declares no {$field} — {$what}."
            );
        }
    }

    /**
     * The forcing function: a relational source spanning more than one scope
     * MUST name a default_scope, and a named default_scope must be one of the
     * scopes actually declared.
     *
     * Same seam and same reasoning as ConnectorApp::withCredentialHandler()'s
     * private-key check: refuse at startup, on the connector author's own
     * deploy, rather than let an unqualified table name resolve ambiguously
     * (or not at all) the first time a model calls the query tool in
     * production. Both checks below are purely local — no network, nothing
     * to await — so there is no cost to running them here, before the worker
     * ever dials the hub.
     *
     * Deliberately InvalidArgumentException rather than this file's usual
     * ConfigException: the mistake here is a bad VALUE relationship between
     * two fields the author supplied (not a missing declaration or a
     * cross-reference to a tool that does not exist), so the SDK's own
     * ArgumentException-equivalent — the same generic exception the .NET SDK
     * throws for this identical check — is the more precise signal.
     *
     * @param  list<string>  $scopes
     */
    private static function validateScopes(string $where, array $scopes, string $default): void
    {
        if (count($scopes) > 1 && $default === '') {
            throw new \InvalidArgumentException(
                'relational_source declares '.count($scopes).' scopes but no default_scope. '
                .'An unqualified table name has to resolve somewhere, and only this connector knows '
                .'where its connection points. Name one of: '.implode(', ', $scopes)
                ." ({$where})"
            );
        }

        // Only meaningful with MORE THAN ONE scope. With zero or one, the
        // declared default carries no information the core needs — there is
        // nothing to disambiguate — and relationalSourceFrom() emits the sole
        // scope instead of this literal, so a mismatch here has no effect on
        // what ships. Throwing on it would make a class-level constant that
        // names the PRODUCTION database break every deployment whose database
        // is named anything else, tests included, for no gain in correctness.
        if (count($scopes) > 1 && $default !== '' && ! in_array($default, $scopes, true)) {
            throw new \InvalidArgumentException(
                "relational_source default_scope `{$default}` is not one of the declared scopes: "
                .implode(', ', $scopes)
                ." ({$where})"
            );
        }
    }

    /**
     * A name an operator can act on. Anonymous classes report as
     * "class@anonymous /path/to/file.php:42", which is the file to open.
     */
    private static function describe(object $o): string
    {
        return $o::class;
    }
}
