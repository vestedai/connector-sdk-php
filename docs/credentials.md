# Per-user credentials

Some integrations act on behalf of the person asking, not on behalf of the
connector. An ERP that enforces its own permissions is the clearest case: if
every call arrives as one service account, the ERP's ACLs do nothing and its
audit log names a robot instead of a human.

Per-user credentials fix that. Each user stores their own credentials for your
integration; the platform hands them to your worker on every tool call.

**The platform cannot read them.** Credentials are sealed in the user's browser
with a public key generated for your connector. The private half lives only on
your worker. A full database dump of the platform leaks nothing.

---

## Opting in

A connector declares a credential schema at registration. Declaring one is what
turns the whole feature on for your integration — a connector that declares
nothing is unaffected in every respect.

The declaration is the `#[CredentialSchema]` and `#[CredentialField]` attributes
on your handler class, described under [The declaration](#the-declaration) below.

```php
use Vested\Connect\Sdk\Attribute\CredentialField;
use Vested\Connect\Sdk\Attribute\CredentialSchema;
use Vested\Connect\Sdk\Credential\CredentialContext;
use Vested\Connect\Sdk\Credential\CredentialValidation;
use Vested\Connect\Sdk\Credential\UserCredentialHandler;

#[CredentialSchema(kind: 'basic', title: 'Al-Saif ERP account')]
#[CredentialField(key: 'username', label: 'ERP username', type: 'text',     required: true)]
#[CredentialField(key: 'password', label: 'ERP password', type: 'password', required: true)]
final class ErpCredentials implements UserCredentialHandler
{
    public function __construct(private readonly ErpClient $erp) {}

    public function validate(CredentialContext $ctx, array $credential): CredentialValidation
    {
        $who = $this->erp->whoami($credential['username'], $credential['password']);

        return $who === null
            ? CredentialValidation::failed('ERP rejected those credentials.')
            : CredentialValidation::ok(['account' => $who->login, 'role' => $who->role]);
    }

    public function revoke(CredentialContext $ctx, array $credential): void
    {
        // Optional: tear down a remote session. Best-effort — the platform
        // deletes its copy regardless of what happens here.
    }
}
```

Register it, with the private key that opens sealed envelopes:

```php
$app->withCredentialHandler(new ErpCredentials($erp));
```

Keys come from `VESTED_CREDENTIAL_PRIVATE_KEY` (or `VESTED_CREDENTIAL_PRIVATE_KEY_FILE`)
when you don't pass them explicitly. Registering a handler without a key throws
at startup rather than failing every credential check later with a puzzling
message.

## Using them in a tool

```php
public function handle(array $args, ToolContext $ctx): array
{
    $creds = $ctx->credential();          // ['username' => '…', 'password' => '…']

    return $this->erp->searchAsUser($creds['username'], $creds['password'], $args['q']);
}
```

`credential()` is lazy and memoized: a tool that never calls it never pays for a
decrypt, and calling it twice costs one key agreement.

Use `$ctx->hasCredential()` if a tool works with or without one.

## What the SDK guarantees

**An envelope sealed for another user throws.** Every envelope is
cryptographically bound to the connector and the user it was sealed for, and
the SDK verifies that binding before handing you plaintext. You cannot
accidentally serve user A's request with user B's credentials — the check is
inside `credential()`, not something you remember to call.

**A tool call without a usable credential never reaches you.** The platform
refuses it and tells the user what to do. By the time your handler runs, the
credential is present and valid.

## The declaration

Both attributes go **above the class**, one `#[CredentialField]` per field, in
the order the user should see them. The platform builds the form from this —
you never write UI.

```php
#[CredentialSchema(kind: 'basic', title: 'Al-Saif ERP account', helpText: 'Ask IT for a service login.')]
#[CredentialField(key: 'username', label: 'ERP username', type: 'text',     required: true)]
#[CredentialField(key: 'password', label: 'ERP password', type: 'password', required: true)]
#[CredentialField(key: 'company',  label: 'Company',      type: 'select',   options: ['KSA', 'UAE'])]
final class ErpCredentials implements UserCredentialHandler
{
    // …
}
```

`kind` is one of `basic`, `token`, `custom`. Field types are `text`, `password`,
`url`, `select`. A `password` field renders masked; `select` needs `options`;
`label` defaults to `key`.

Everything here is checked when you call `build()`, not when you connect: a
blank title, an unknown kind or type, a duplicate field key, an optionless
`select` or a schema with no fields at all throws `ConfigException` at startup,
because the alternative is a rejected registration or a form the user cannot
complete.

Registering a handler **without** `#[CredentialSchema]` throws. With no schema
the platform renders no form, so nobody can save a credential and none of your
tools are gated — every call keeps running as the connector's own shared
account, which is the misattribution this feature exists to end.

Put both attributes on the handler class **you register**. PHP does not inherit
class attributes, so a subclass of an annotated handler declares nothing; the
error says so by name.

## Key rotation

An operator can rotate your connector's keypair. Envelopes sealed under the old
key stop being readable, so affected users are asked to re-enter.

To ride out the overlap, keep both keys in the ring — newest first, separated by
a blank line in `VESTED_CREDENTIAL_PRIVATE_KEY`. The SDK tries each in turn.

## Things worth knowing

- **`display` is shown to the user.** Put an account name or role in it, never
  the credential.
- **Error text from `failed()` is shown verbatim.** Don't include stack traces
  or internal hostnames.
- **Automated runs need an owner.** A scheduled workflow uses the credentials of
  the person who owns it. A workflow instance with no owner at all is refused
  rather than run as an arbitrary employee.
