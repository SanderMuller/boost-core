# Install and first run

```bash
composer require --dev sandermuller/package-boost-php
```

PHP 8.3 or later. `sandermuller/boost-core` (the sync engine) and
`stolt/lean-package-validator` (the `lean` command's checker) come in
transitively. Do **not** require `boost-core` separately: one package is the whole
install, and the auto-sync callback lives under this package's own namespace, so
your `composer.json` never names the transitive dependency.

## First run

```bash
vendor/bin/boost install                     # pick agents, allowlist vendors, write boost.php
vendor/bin/boost sync                        # fan skills + guidelines out to the selected agents
vendor/bin/package-boost-php gitattributes   # write or refresh the managed .gitattributes block
vendor/bin/package-boost-php lean            # confirm the archive is lean
```

Generated agent directories (`.claude/`, `.cursor/`, `.codex/`, and so on) are
added to `.gitignore` automatically. Root-level guidance files (`AGENTS.md`,
`CLAUDE.md`) stay tracked. Edit `.ai/` only, then re-run `vendor/bin/boost sync`.
[File ownership](/guide/file-ownership) explains the split.

## Verify

```bash
vendor/bin/boost where     # what ships, and which package supplied it
vendor/bin/boost doctor    # offline health check
```

## Testing the package itself

```bash
composer test   # Pest suite
composer qa     # Rector + Pint + PHPStan + the .gitattributes validator
```
