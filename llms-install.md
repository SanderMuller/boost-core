# Installing the boost family — guide for AI agents

You are an AI coding agent installing the **sandermuller boost** AI-config sync
toolkit into the user's repository. This file is the executable install guide.
Work through it top to bottom. Prefer the most specific family member; install
exactly one. Stop and ask the user only where this guide says to.

`boost-core` is the sync engine: author skills, guidelines, and slash-commands
once under `.ai/`, and `boost sync` fans them out to up to nine agents (Claude
Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). You almost
never install `boost-core` directly — install the **family wrapper** that matches
what the repo is, and it pulls `boost-core` in.

---

## Step 1 — Detect what the repo is

Inspect the repo before choosing. Run these checks (read files; do not guess):

- **Is it a library/package or an application?** Open `composer.json` →
  `"type"`. `"type": "library"` (and a `require` aimed at consumers, not an app)
  means it is a **package**. An app usually has `"type": "project"`, a `public/`
  or web root, app-level config, and a lockfile committed.
- **Is it Laravel?** Look for `laravel/framework` in `composer.json` require, an
  `artisan` file at root, and a `bootstrap/app.php`. A **Laravel package** has
  `illuminate/*` or `laravel/framework` in `require`/`require-dev` plus
  `orchestra/testbench` in `require-dev`, and a service provider under `src/`.
- **Is it none of the above** (you are building a custom skill bundle, a CLI
  tool, or want only the bare engine)? Use `boost-core` directly.

## Step 2 — Pick exactly one family member

| Repo is…                                    | Install (`composer require --dev …`) | First-run command                   |
|---------------------------------------------|--------------------------------------|-------------------------------------|
| A **Laravel application**                   | `sandermuller/project-boost-laravel` | `php artisan project-boost:install` |
| A **PHP application** (Symfony / plain PHP) | `sandermuller/project-boost-php`     | `vendor/bin/boost install`          |
| A **Laravel package** (Testbench-based)     | `sandermuller/package-boost-laravel` | `vendor/bin/boost install`          |
| A **framework-agnostic Composer package**   | `sandermuller/package-boost-php`     | `vendor/bin/boost install`          |
| **Your own skill bundle / custom tooling**  | `sandermuller/boost-core`            | `vendor/bin/boost install`          |

Tie-breakers:
- Laravel *app* vs Laravel *package*: a package has `orchestra/testbench` in
  `require-dev` and no `artisan` file → use `package-boost-laravel`. An app has
  `artisan` at root → use `project-boost-laravel`.
- A non-Laravel package → `package-boost-php`. A non-Laravel app → `project-boost-php`.
- Wrappers pull `boost-core` transitively. **Never** require `boost-core`
  alongside a wrapper.

## Step 3 — Install and configure

Pick the matching subsection. Each ends with a verification step — run it.

### A. Laravel application → `project-boost-laravel`

This wrapper coexists with `laravel/boost` (it owns the MCP server and Laravel
docs; this wrapper owns the agent-file fan-out). Use the artisan entry points,
**not** `vendor/bin/boost sync` directly — the bare CLI bypasses laravel/boost
injection.

```bash
composer require --dev sandermuller/project-boost-laravel
php artisan project-boost:install      # runs boost:install --mcp, then project-boost:sync
```

Config lives at `.config/boost.php` (canonical) or `boost.php`. It is separate
from laravel/boost's own `boost.json`. A typical config:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])    // see Step 4
    ->withTags([                          // see Step 5
        Tag::Laravel,
        Tag::Php,
    ]);
```

Wire the Composer hook to the **artisan** sync (not `BoostAutoSync::run`):

```json
"scripts": {
    "post-install-cmd": ["@php artisan project-boost:sync"],
    "post-update-cmd":  ["@php artisan project-boost:sync"]
}
```

Verify: `php artisan project-boost:sync --dry-run` reports the pipeline with no
errors, and `vendor/bin/boost where` lists resolved skills/guidelines.

### B. PHP application (non-Laravel) → `project-boost-php`

```bash
composer require --dev sandermuller/project-boost-php
vendor/bin/boost install      # interactive: pick agents; scaffolds boost.php
vendor/bin/boost sync
```

Config (`boost.php` or `.config/boost.php`) — allowlist the wrapper so its
bundled app-dev skills ship:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors(['sandermuller/project-boost-php']);
```

Composer hook:

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"]
}
```

Verify: `vendor/bin/boost sync --check` exits 0 with no drift.

### C. Laravel package → `package-boost-laravel`

```bash
composer require --dev sandermuller/package-boost-laravel
vendor/bin/boost install
vendor/bin/boost sync
```

Config — allowlist the wrapper chain and tag for Laravel + PHP:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors([
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withTags([
        Tag::Php,
        Tag::Laravel,
    ]);
```

This wrapper writes `.mcp.json` (pointing at `vendor/bin/testbench boost:mcp`)
only when **all** hold: `laravel/boost` and `orchestra/testbench` are in
`require-dev`, and `Agent::CLAUDE_CODE` is selected. In a package there is no
`artisan` — use `vendor/bin/testbench …` for artisan-style commands.

Composer hook: `SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run` (as in B).

Verify: `vendor/bin/boost sync --check` exits 0; if MCP conditions hold, confirm
`.mcp.json` was written.

### D. Framework-agnostic Composer package → `package-boost-php`

```bash
composer require --dev sandermuller/package-boost-php
vendor/bin/boost install
vendor/bin/boost sync
vendor/bin/package-boost-php gitattributes   # maintain the managed .gitattributes block
vendor/bin/package-boost-php lean            # verify the dist archive is lean
```

Config — allowlist the wrapper; add `release-automation` if the package ships
CI-managed releases:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors(['sandermuller/package-boost-php'])
    ->withTags([
        Tag::Php,
        Tag::Github,
        'release-automation',
    ]);
```

Composer hook: `SanderMuller\\PackageBoostPhp\\Scripts\\AutoSync::run`.

Verify: `vendor/bin/boost sync --check` exits 0; `vendor/bin/package-boost-php lean`
reports a lean archive.

### E. Bare engine → `boost-core`

```bash
composer require --dev sandermuller/boost-core
vendor/bin/boost install
vendor/bin/boost sync
```

Minimal config (`boost.php` or `.config/boost.php`):

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE]);
```

You supply your own skills under `.ai/skills/`, guidelines under
`.ai/guidelines/`, commands under `.ai/commands/`. Composer hook:
`SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run`.

Verify: `vendor/bin/boost sync --check` exits 0.

## Step 4 — Choose agents (`withAgents`)

Set the agents the user actually uses. Detect existing ones by directory:
`.claude/` → Claude Code, `.cursor/` → Cursor, `.github/` (Copilot prompts) →
Copilot, `.codex/`/`AGENTS.md` → Codex, `.gemini/` → Gemini, `.junie/` → Junie,
`.kiro/` → Kiro, `.opencode/` → OpenCode, `.agents/` → Amp. If none are obvious,
ask the user which agents they use, or default to `Agent::CLAUDE_CODE`.

Valid cases: `CLAUDE_CODE`, `CURSOR`, `COPILOT`, `CODEX`, `GEMINI`, `JUNIE`,
`KIRO`, `OPENCODE`, `AMP`.

## Step 5 — Choose tags (`withTags`)

Tags gate which **vendor / remote** skills and guidelines ship. The rule: a
tagged skill ships only when **every** tag it declares is present in the
project's `withTags()` (subset rule). Untagged skills always ship. Host-authored
`.ai/skills/` are never filtered.

Match tags to the stack you detected:

- Always include `Tag::Php` for a PHP project.
- `Tag::Laravel` for Laravel apps/packages.
- Frontend stack: `Tag::Frontend`, plus `Tag::Livewire`, `Tag::Inertia`,
  `Tag::Filament`, `Tag::Volt`, `Tag::Flux`, `Tag::Tailwind` as detected.
- `Tag::Pest` if `pestphp/pest` is a dev dep; `Tag::Database` for DB-heavy work.
- `Tag::Github` / `Tag::Jira` / `Tag::GithubIssues` for the issue tracker in use.
- String tags are valid too (the vocabulary is open): e.g.
  `'release-automation'`, `'boost-extension'`.

The `Tag` enum is a convenience; any string is a valid tag. After syncing, run
`vendor/bin/boost tags` to see every tag installed skills declare, which ones
`withTags()` currently filters out, and what to add to receive them. If a stack
is detected but the matching tag is missing, add it and re-sync.

## Step 6 — Final verification

Run the family member's verify command from Step 3 and confirm exit 0. Then:

- `vendor/bin/boost where` — confirm the expected skills/guidelines resolve from
  host / vendor / remote with no surprises.
- `vendor/bin/boost doctor` — advisory health check (config load, remote
  sources, emitters, token leaks). It exits 0 even with warnings; read them.
- Confirm guidance files (`CLAUDE.md`, `AGENTS.md`, etc.) were generated for the
  selected agents, and that skill/command directories were created.

Report to the user: which family member you installed and why, the agents and
tags you configured, the output of the verify command, a short note on how the
setup works (author under `.ai/`, `boost sync` fans out to the agents), and any
follow-ups (tags to add, agents to enable, a Composer autosync hook to wire).

---

Links are absolute so they resolve when this file is fetched outside the repo.

- Full engine documentation, CLI reference, environment variables:
  [README.md](https://github.com/sandermuller/boost-core/blob/main/README.md)
- Frozen public/semver surface (config API, CLI, hooks, contracts):
  [PUBLIC_API.md](https://github.com/sandermuller/boost-core/blob/main/PUBLIC_API.md)
- File ownership (what is tracked vs gitignored, the manifest, reaping):
  [docs/file-ownership.md](https://github.com/sandermuller/boost-core/blob/main/docs/file-ownership.md)
- Project Conventions (JSONSchema slot fill-in for vendor skills):
  [docs/conventions.md](https://github.com/sandermuller/boost-core/blob/main/docs/conventions.md)
- Family repos:
  [project-boost-php](https://github.com/sandermuller/project-boost-php) ·
  [project-boost-laravel](https://github.com/sandermuller/project-boost-laravel) ·
  [package-boost-php](https://github.com/sandermuller/package-boost-php) ·
  [package-boost-laravel](https://github.com/sandermuller/package-boost-laravel)
- Coexists with [`laravel/boost`](https://github.com/laravel/boost) in Laravel
  projects via `project-boost-laravel`.
