# Install and first run

```bash
composer require --dev sandermuller/package-boost-laravel
```

PHP 8.3 or later, and Laravel 12 or 13. `sandermuller/package-boost-php`,
`sandermuller/boost-core`, and `stolt/lean-package-validator` all come in
transitively. Do **not** require any of them separately: they resolve through
this umbrella, and one package gives a Laravel package author the whole stack.

Internally the package pins `boost-core` directly, so `McpJsonEmitter` is
guaranteed the iterable `FileEmitter` contract. That is an implementation floor,
not something you declare.

## First run

```bash
vendor/bin/boost install   # pick agents, allowlist vendors, write boost.php
vendor/bin/boost sync      # fan skills + guidelines out to the selected agents
```

If `laravel/boost` and `orchestra/testbench` are in your dev dependencies and
Claude Code is one of your active agents, `boost sync` writes `.mcp.json` for
you.

Generated agent directories (`.claude/`, `.cursor/`, and so on) are added to
`.gitignore`. Edit `.ai/` only, then re-run `vendor/bin/boost sync`.

## Verify

```bash
vendor/bin/boost where                       # what ships, and which package supplied it
vendor/bin/boost doctor                      # offline health check
vendor/bin/package-boost-php lean            # inherited: confirm the archive is lean
```

## Testing the package itself

```bash
composer test
```
