# package-boost-laravel

`sandermuller/package-boost-laravel` is the family member for **Laravel package
authors**. It inherits the framework-agnostic toolkit from
[`package-boost-php`](/packages/package-boost-php/) and layers on Laravel
context: Testbench conventions, cross-version Laravel support, CI matrix
diagnostics, and the `McpJsonEmitter`.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/package-boost-laravel) &middot; [Packagist](https://packagist.org/packages/sandermuller/package-boost-laravel) &middot; [Releases](https://github.com/SanderMuller/package-boost-laravel/releases) &middot; [Changelog](https://github.com/SanderMuller/package-boost-laravel/blob/main/CHANGELOG.md)

Each family package has its own repository and its own release cadence. This site is built from the `boost-core` repository, so a documentation fix goes there, and a code issue goes to the repository above.
:::

Where `laravel/boost` targets Laravel **application** developers, this package
targets the people building Laravel **packages** — the dev-time codebase where
`app/`, `bootstrap/`, and `.env` do not exist, and `php artisan` does not apply.

## `McpJsonEmitter`

This is what the package adds that no other tool does. It updates `.mcp.json` on
every `boost sync`, idempotently, with the command pointed at
`vendor/bin/testbench boost:mcp` rather than `php artisan`, so the MCP server
actually boots in a package codebase.

It merges rather than overwrites. Only `mcpServers.laravel-boost` is touched, so
your own servers, other top-level keys, and extra keys on that entry (`env`,
`alwaysLoad`) survive. A `.mcp.json` it cannot parse is left alone.

The emitter fires only when all three conditions hold:

1. `laravel/boost` is in your dev dependencies;
2. `orchestra/testbench` is in your dev dependencies;
3. `Agent::CLAUDE_CODE` is in your active agents.

Otherwise it returns null and skips silently.

`laravel/boost` writes `.mcp.json` once at install time, against `php artisan` —
a command that does not exist here. That is the gap this emitter closes.

## Three Laravel skills

All three are untagged, so they ship whenever this package is installed.

| Skill | When it loads |
|---|---|
| `package-development` | Testbench conventions: `vendor/bin/testbench` versus `php artisan`, service-provider registration in `testbench.yaml`, the `workbench/` layout for fixtures, migrations, routes, and factories |
| `cross-version-laravel-support` | Supporting several Laravel majors in one release: `^12.0\|\|^13.0` constraint patterns, version-specific API shims, and the CI matrix shape including `prefer-lowest` |
| `ci-matrix-troubleshooting` | Debugging "fails on prefer-lowest" and "fails on Laravel 13 but not 12" matrix failures |

## One Laravel guideline

| Guideline | Scope |
|---|---|
| `laravel-packages` | The detection rule (`require.illuminate/*` or `require.laravel/framework`), Testbench context, the artisan-substitution table, and a cross-version compatibility pointer |

It composes with the framework-agnostic `foundation` guideline inherited from
`package-boost-php`.

## What it inherits

Everything `package-boost-php` ships: the `foundation` guideline, the `lean` and
`gitattributes` CLI commands, and the `lean-dist` skill. The release-flow content
skills (`readme`, `release-notes`, `upgrading`) come from
[`boost-skills`](/packages/boost-skills/) under the `release-automation` tag.

`skill-authoring` and `writing-file-emitter` ship too, behind the
`boost-extension` tag. Declare it if your package extends the engine with a
custom `FileEmitter` — this package does exactly that, for `McpJsonEmitter`.
