# Install and first run

```bash
composer require --dev sandermuller/project-boost-laravel
```

`laravel/boost` and `sandermuller/boost-core` come in transitively. Do **not**
require `boost-core` separately: it resolves through this package.

## First run

```bash
php artisan project-boost:install
```

The wrapper does two things:

1. Runs `php artisan boost:install --mcp`. `laravel/boost` writes its MCP client
   config, and the `--mcp` flag keeps its `GuidelineWriter` and `SkillWriter`
   dormant, so this package owns the agent-file fan-out from here on.
2. Runs `php artisan project-boost:sync`. It discovers the bundled skills and
   guidelines, renders the Blade templates, applies your `withTags()` filter, and
   fans out to every agent in `boost.php`.

In CI, Docker, or any non-TTY shell, the wrapper detects the absent interactive
STDIN and skips `boost:install` entirely. It calls `laravel/boost`'s `McpWriter`
directly, once per agent in `boost.php`. No prompts, no multiselect, no crash.

::: warning
Running `php artisan boost:install` **without** `--mcp` fires `laravel/boost`'s
`GuidelineWriter` and `SkillWriter`, which then race this package over `CLAUDE.md`
and the per-agent skill directories. Always go through `project-boost:install`,
or pass `--mcp` yourself. The `suppress_upstream_writers` flag on the
[configuration page](/packages/project-boost-laravel/configuration) is the
guardrail for muscle-memory mistakes.
:::

## Already had agent files?

If `boost:install` has already seeded its guidelines into your agent files, and
you have hand-edited them, run the takeover once before syncing:

```bash
php artisan project-boost:reconcile
```

It captures your edits into `.ai/guidelines/` and backs the files up, so the
markerless wholesale sync never drops them. The mechanics are in
[Coexistence with `laravel/boost`](/guide/laravel-coexistence).

## Verify

```bash
php artisan project-boost:sync --dry-run   # preview the full pipeline
php artisan project-boost:where            # the laravel/boost set this package injects
vendor/bin/boost where                     # host, vendor, and remote origins
```

## Troubleshooting

**`No boost config found (expected boost.php or .config/boost.php)`** — create
one, or run `vendor/bin/boost install`.

**`... listed more than once`** — a `withRemoteSkills()` source publishes a skill
name that another source also publishes. Rename or exclude one side.

**`... also published by a scanned vendor`** — an allowlisted package publishes a
skill that collides with one injected from `laravel/boost`. Exclude it with
`->withExcludedSkills(['vendor/pkg:skill-name'])`.

**Rendered output contains literal `@php` or <code v-pre>{{ ... }}</code>** — the Blade renderer
did not fire. Confirm `laravel/boost` is installed, and run the sync through
`php artisan project-boost:sync`. The bare CLI never boots Laravel, so it cannot
render Blade.

**Every file reports `unchanged` on a second sync** — expected. The writer is
content-aware.

## Testing the package itself

```bash
composer test
```

The Pest suite covers discovery, version resolution, and the suppress-upstream
listener, with Testbench-backed feature tests for `project-boost:install`'s
TTY-versus-non-TTY branching.

`.github/workflows/ci-smoke.yml` runs the consumer install path end to end on
every push and pull request. It creates a fresh `laravel/laravel` application,
installs the package from the checkout, runs
`project-boost:install --no-sync --no-interaction` and asserts `.mcp.json` lands
with the `laravel-boost` server entry, then runs `project-boost:sync` and asserts
no Blade directives leak into the rendered output.
