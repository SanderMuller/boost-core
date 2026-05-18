---
name: writing-file-emitter
description: 'Implement a FileEmitter for boost-core to emit a custom file (e.g. .mcp.json, .editorconfig) into the host project during boost:sync.'
---

# Writing a FileEmitter

## When to apply

- Adding a new plugin to a Composer package that publishes skills via boost-core
- Asked "how do I make boost-core write file X when condition Y holds?"
- Reviewing a PR that adds an emitter

## The contract

A FileEmitter is a single-method interface in `SanderMuller\BoostCore\Contracts\FileEmitter`:

```php
public function emit(SyncContext $ctx): ?EmittedFile;
```

Return `EmittedFile` to write a file. Return `null` to skip (e.g. an
optional dependency isn't installed). Throwing is recorded as `errored`
and sync continues with other emitters.

The contract is `@experimental` — the shape may change before v1.0
stable. Pin to an exact boost-core version if you build against this.

## Minimal example

```php
namespace YourVendor\YourPackage\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

final class YourEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): ?EmittedFile
    {
        if (! $ctx->packages->has('some/required-dep')) {
            return null;
        }

        return new EmittedFile(
            relativePath: '.your-config.json',
            content: json_encode(['key' => 'value']) . "\n",
        );
    }
}
```

## Registration

In your package's `composer.json`:

```json
{
    "extra": {
        "boost": {
            "emitters": [
                "YourVendor\\YourPackage\\Emitters\\YourEmitter"
            ]
        }
    }
}
```

Emitters are only loaded from **allowlisted vendors** (per the host's
`boost.php` `withAllowedVendors([])` declaration). Untrusted vendors'
emitters never instantiate.

## Anti-patterns

- **Expensive constructors.** The constructor runs as soon as the
  vendor is allowlisted, before any `shouldEmit` check. Keep
  constructors parameterless and side-effect-free.
- **Writing outside the project root.** Path traversal (`../`,
  absolute paths) is rejected by `FileWriter`. Always emit a relative
  path under the project root.
- **Conditional based on data that can change between calls.** Don't
  cache `$ctx->packages->has(...)` results — call them each time. The
  SyncContext is read-only and consistent within a single sync.
- **Multiple emitters claiming the same path.** First wins; subsequent
  emitters get an `errored` result. Either pick distinct paths or use
  one emitter that branches internally.

## See also

- `SanderMuller\BoostCore\Sync\SyncContext` for what's available on `$ctx`
- `package-boost-laravel`'s `McpJsonEmitter` for a real working example
- The architecture plan's "FileEmitter contract" section for the design rationale
