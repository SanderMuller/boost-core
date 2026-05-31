# boost-core

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/boost-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/boost-core/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-core.svg?style=flat-square)](LICENSE)
[![Laravel Boost](https://badge.laravel.cloud/boost-badge.svg?style=flat-square)](https://github.com/laravel/boost)

> AI agent configuration sync for any PHP project. Write skills, guidelines, and commands once in `.ai/`; boost-core publishes them to nine agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). No framework dependency.

Tag filtering, remote skill sources, vendor allowlist, `boost doctor`, `boost where` origin tracing, and `.ai/commands/` argument-transpiling fanout. Coexists with [`laravel/boost`](https://github.com/laravel/boost) in Laravel projects via [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel).

## Install

`boost-core` is the engine. You rarely install it directly — you install the family package that matches what you're building, and it pulls `boost-core` in.

| You're building                              | Install                                                                                       | Ships                                                                                      |
|----------------------------------------------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| A PHP application (not a package)            | [`sandermuller/project-boost`](https://github.com/sandermuller/project-boost)                 | App-dev skills — DDD layering, repository pattern, DI, domain modeling, legacy coexistence |
| A Laravel application                        | [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) | `laravel/boost` MCP coexistence + nine-agent fanout + tag filter + remote skills           |
| A framework-agnostic Composer package        | [`sandermuller/package-boost-php`](https://github.com/sandermuller/package-boost-php)         | Package-author skills + `lean` / `gitattributes` commands                                  |
| A Laravel package                            | [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel) | Laravel-package skills + `McpJsonEmitter`                                                  |
| **Your own skill bundle, or custom tooling** | **`sandermuller/boost-core` directly**                                                        | **Just the sync engine — you supply the skills  ← you are here**                           |

```bash
composer require --dev sandermuller/boost-core
```

## What you get

|                          | `laravel/boost`          | `boost-core`                                                                                                         |
|--------------------------|--------------------------|----------------------------------------------------------------------------------------------------------------------|
| Framework scope          | Laravel only             | **Any PHP** (Laravel, Symfony, plain-PHP, packages)                                                                  |
| Skill sources            | bundled + `.ai/skills/`  | `.ai/skills/` + Composer packages (`resources/boost/skills/`) + `withRemoteSkills()` + `withAllowedVendors()` filter |
| Tag filtering            | none                     | `withTags()` subset rule                                                                                             |
| Remote skill sources     | none                     | `withRemoteSkills()` — GitHub bundles + path imports                                                                 |
| User-scope sync          | none                     | `boost sync --scope=user` for globally-installed CLI tools                                                           |
| Origin tracing           | none                     | `boost where` + `boost where --diff` (host / vendor / remote / shadow)                                               |
| Doctor / path-repo audit | none                     | `boost doctor`, `boost doctor --check-versions`                                                                      |
| `.ai/commands/` fan-out  | none                     | per-agent argument transpilation across 7 emit targets                                                               |
| Project Conventions      | none                     | JSONSchema-validated slot fill-in via `boost validate` / `boost slots` (0.8.0)                                       |

MCP server + Laravel docs API are `laravel/boost`'s domain — boost-core defers to them in Laravel projects (see [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) for coexistence).

## Usage

```bash
vendor/bin/boost install   # generate boost.php (if missing) + interactive picker for agents, vendor allowlist, and tags
vendor/bin/boost sync      # fan out to selected agents
```

boost-core is a plain library — it runs no install-time code of its own. To re-sync automatically after a `composer install` / `composer update`, wire the `BoostAutoSync` script callback into your project's `composer.json` (see below); otherwise run `vendor/bin/boost sync` yourself, e.g. in CI. `BOOST_SKIP_AUTOSYNC=1` disables the callback.

For tooling authors who want to publish their own skills to every AI agent on the user's machine:

```bash
vendor/bin/boost sync --scope=user   # ~/.{agent}/skills/<vendor>__<package>/<skill>/SKILL.md
```

After `composer global require`-ing one or more skill-bearing packages, run `vendor/bin/boost sync --scope=user --all` once — it user-scope-syncs every globally-installed package that ships `resources/boost/skills/`. User scope publishes a package's skills **wholesale**: there is no `boost.php` in play, so tag filters (`withTags()`) and the vendor allowlist — both project-scope controls — do not apply. Each package's paths are namespaced by its full `vendor/package` slug, with `/` replaced by `__`. That sequence can't occur inside a Composer package name, so two packages never produce the same slug — `vendor-a/foo` and `vendor-b/foo` land in separate directories.

## Automating the sync

### In a consumer project (composer install hook)

`SanderMuller\BoostCore\Scripts\BoostAutoSync::run` is a cross-platform Composer script callback that consumer packages can wire into their own `post-install-cmd` / `post-update-cmd` hooks:

```json
"scripts": {
    "post-install-cmd": [
        "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
    ],
    "post-update-cmd": [
        "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
    ]
}
```

It checks `Event::isDevMode()`, resolves `composer config.bin-dir`, runs `vendor/bin/boost sync`, surfaces non-zero exits through Composer's IO, and prints the one-line sync summary on installs that actually wrote or deleted files — staying silent on a true no-op (`wrote=0, deleted=0`). Works on Windows + Unix. Honors `BOOST_SKIP_AUTOSYNC=1`. boost-core ships no Composer plugin — wiring this callback is how a consuming project makes a `composer install` re-sync; without it, run `vendor/bin/boost sync` manually or in CI.

> [!NOTE]
> **No output = success.** `BoostAutoSync::run` stays silent on a true no-op (`wrote=0, deleted=0`) by design — the hook fired, the sync ran, nothing changed. Output appears only when something actually changed or an error surfaced. If you want a positive "ran OK" confirmation on every install (helpful when debugging "did the hook fire?"), use `BoostAutoSync::runWithSummary` instead (next subsection).

> [!IMPORTANT]
> **If you're on Laravel + [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)**, use `@php artisan project-boost:sync` instead of `BoostAutoSync::run`. The artisan-wrapped path runs through the Laravel container, which bootstraps `BladeRenderer` correctly + delivers laravel/boost's bundled skills to every active agent (Cursor / Copilot / Codex / etc.). The bare-CLI path bypasses both — Claude Code may mask the absence locally via the MCP server, but the cross-agent skill set silently misses the bundled skills. See `project-boost-laravel`'s install guide for the canonical `composer.json` `scripts` shape.

For user-invoked scripts (`composer sync-ai`, etc.) where silence on success reads as a no-op, use `BoostAutoSync::runWithSummary` instead — it streams the binary's one-line success summary on *every* successful sync, including the no-op installs `run` keeps quiet:

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

### In a CLI tool you publish (self-sync from bin script)

A tool installed with `composer global require` can keep its own bundled skills current by self-syncing from its bin script — no Composer plugin, no manual `boost sync --scope=user`:

```php
#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\SanderMuller\BoostCore\Scripts\BoostAutoSync::syncUserScopeOnce(
    packageRoot: dirname(__DIR__),
    packageName: 'your-vendor/your-tool',
);

// ... the tool's own dispatch ...
```

`syncUserScopeOnce()` runs a user-scope sync (into `~/.{agent}/skills/<vendor>__<package>/`) the first time it sees a given version of the tool, then writes a per-version sentinel so later runs are free. `syncUserScope()` is the ungated form. Both honor `BOOST_SKIP_AUTOSYNC=1` and never throw — the tool keeps running even if its sync fails.

## Commands

`.ai/commands/*.md` holds reusable prompt templates — the slash-command files agents surface in their command palette. `boost sync` fans each one out to the seven agents with a command surface:

| Agent       | Command target                                 |
|-------------|------------------------------------------------|
| Claude Code | `.claude/commands/`                            |
| Cursor      | `.cursor/commands/`                            |
| Copilot     | `.github/prompts/` (as `<name>.prompt.md`)     |
| Junie       | `.junie/commands/`                             |
| OpenCode    | `.opencode/commands/`                          |
| Amp         | `.agents/commands/`                            |
| Kiro        | `.kiro/skills/<name>/SKILL.md` (slash-command) |

Kiro has no dedicated command directory — its committed `.kiro/skills/` is the slash-command surface, so a `.ai/commands/<name>.md` lands as `.kiro/skills/<name>/SKILL.md`.

Codex and Gemini have no committable target boost-core can write into — Codex's prompts (`~/.codex/prompts/`) are deprecated and personal-only, Gemini uses TOML. When `.ai/commands/` is populated and one of those agents is in `withAgents()`, `boost doctor` prints the manual authoring path so the gap isn't silent. The source directory defaults to `.ai/commands`; override it with `->withCommandsPath(...)` in `boost.php`.

**Argument placeholders are transpiled per-agent.** Author once in `.ai/commands/<name>.md` using the canonical syntax — `$ARGUMENTS` (unsplit), `$1`/`$2`/… (one-indexed positional), `$name` (named, optionally declared in frontmatter `arguments:`), `\$ARGUMENTS`/`\$1`/`\$name` for literal escapes — and boost-core converts to each agent's native shape on sync. Claude Code is zero-indexed natively so the transpiler emits `$0` for source `$1`; Copilot gets `${input:args}` / `${input:argN}` / `${input:name}`; Junie auto-names positional placeholders to `$argN` with a warning recommending you declare them in `arguments:`; OpenCode + Kiro get their native shapes. Cursor + Amp have no placeholder support — the source emits verbatim with a per-command warning. The bundled `command-arguments` skill documents the canonical syntax + per-agent transpilation table + the lossy modes.

## Managed `.gitignore`

`boost:sync` maintains a managed block in `.gitignore` so generated agent dirs (`.claude/skills/`, `.cursor/skills/`, `CLAUDE.md`, `AGENTS.md`, ...) stay out of version control. Edit skills in `.ai/` only; the fan-out regenerates on next install.

Opt out per project:

```php
return BoostConfig::configure()
    ->withGitignoreManagement(false)
    ->withAgents([...]);
```

Or one-off via env var (useful for CI / ephemeral Docker installs):

```bash
BOOST_SKIP_GITIGNORE=1 composer install
```

## Skills from packages

Skills aren't only authored in a project's own `.ai/skills/`. Any Composer package can ship them: it places skills at `resources/boost/skills/<name>/SKILL.md`, and a consuming project picks them up by allowlisting the vendor in `boost.php`:

```php
return BoostConfig::configure()
    ->withAllowedVendors(['vendor/package']);
```

On the next `boost:sync`, that package's skills fan out to every selected agent alongside the project's own. This is how a team distributes one curated skill set across many repos — author once in a package, allowlist everywhere.

[`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) is one example of the pattern — a package of skills and nothing else, several tagged for conditional sync (see below). It's a personal catalog shared as an illustration; anyone can publish their own private or public skill bundle the same way.

## Skill rendering

Skill files default to plain markdown (`SKILL.md`). For template-flavored content — Blade, Twig, anything that needs a render step before fan-out — register a `SkillRenderer` in `boost.php`:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withSkillRenderers([new BladeRenderer]);
```

The dispatcher matches **longest-extension-first**, so a `BladeRenderer` claiming `blade.php` handles `SKILL.blade.php` even if another renderer claims `php`. The implicit `PassthroughRenderer` always handles `.md` and is re-appended after any `withDisabledRenderers([FQCN])` deny-list — so `.md` always renders. A user-registered renderer claiming `md` wins over the passthrough by first-registered order.

Render failures default to warn-and-skip: an error is recorded in `SyncResult::errors` and the file is dropped from the sync. Set `BOOST_RENDER_STRICT=1` to escalate the first failure to a sync-aborting `SkillRenderException`. The flag is separate from `BOOST_REMOTE_STRICT` so a project can keep renders lenient (a single broken Blade skill should not abort CI) while making remote-source resolution strict, or vice versa.

The renderer contract is `@experimental` — the shape will change before v1.0 stable; pin to an exact boost-core version if building against it. Reference consumer: [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) ships a `BladeRenderer` that delegates to laravel/boost's `RendersBladeGuidelines` trait, so `.ai/<pkg>/skill/<name>/SKILL.blade.php` files render with the `$assist = GuidelineAssist` runtime context they expect.

### Wrapper-injection contract (`BoostWrapperContract`)

Wrapper packages that inject skills/guidelines at sync time via `SyncEngine::sync()`'s `injectedVendorSkills` / `injectedVendorGuidelines` args (e.g. `project-boost-laravel` injecting laravel/boost's bundled skills) declare a `BoostWrapper` class implementing `SanderMuller\BoostCore\Contracts\BoostWrapperContract` at any of the package's PSR-4 prefixes:

```php
namespace SanderMuller\ProjectBoostLaravel;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

final class BoostWrapper implements BoostWrapperContract
{
    /** @param  list<string>  $activeAgents  agent enum values in withAgents(...) */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        return ['.agents/skills/some-injected-skill/SKILL.md', /* ... */];
    }
}
```

`$activeAgents` is the project's `withAgents(...)` set (agent enum values); use it with boost-core's `AgentTarget` API to compute the per-agent emit paths (`.claude/skills/…` for Claude Code, the shared `.agents/skills/…` pool for Cursor / Copilot / Codex / etc.). `boost sync` reads the declared emit-paths and excludes them from stale-file cleanup, so a **bare-CLI** `boost sync` / `boost sync --check` no longer false-positive-flags wrapper-injected files for deletion (they were written by the wrapper's canonical entry point, which the bare CLI doesn't carry the injection args for). Without the class, bare-CLI sync falls back to strict drift comparison — correct but noisier; the `boost doctor` entry-point banner still points operators at the wrapper's canonical sync command. The class must declare a non-empty PSR-4 prefix and should not return guidance-file paths (`CLAUDE.md` / `AGENTS.md` / `GEMINI.md`) — those are wholesale boost-owned (markerless, 0.12.0+) and assembled centrally, not via injected emit-paths. The contract covers stale-file-cleanup exclusion only; it does not regenerate wrapper-injected guidance *content* on a bare CLI run — that needs the wrapper's canonical entry point.

## Remote skill sources

Not every useful skill ships as a Composer package. GitHub repos shipping `.skill` ZIP release bundles, single-skill repos with a root-level `SKILL.md`, and mega-repos to cherry-pick subdirs from are all common. `withRemoteSkills()` declares them directly in `boost.php`, with the same `composer install / update` lifecycle:

```php
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withRemoteSkills([
        // Bundle mode — fetches the named `.skill` release asset and unzips it.
        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
            'composer-upgrade',
            'phpstan-developer',
        ]),

        // Path mode — fetches the repo tarball at the given ref and extracts
        // the named subdirs. `.` covers a whole-repo-is-one-skill layout.
        RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]),
    ]);
```

Each fetched skill fans out alongside host and vendor skills — same `.{agent}/skills/<skill-name>/SKILL.md` layout, same `withTags()` filtering via `metadata.boost-tags`, same `withExcludedSkills(['<owner>/<repo>:<skill-name>'])` deny-list. Removing an entry from `withRemoteSkills()` prunes its agent-dir output on the next sync.

**Cache + offline behavior.** Fetched bundles and tarballs land in `${BOOST_CACHE_HOME:-${XDG_CACHE_HOME:-$HOME/.cache}}/boost/remote-skills/<owner>__<repo>/<ref>/`. Pinned refs (a tag, a 40-char SHA) cache forever; moving refs (`'main'`, `'latest'`, a branch name) re-resolve every 24h. `boost sync --check` is offline-only — it never hits the network. `boost doctor` lists every remote source, flags moving refs with a `⚠`, and reports per-skill cache presence. `boost doctor --check-versions` is an opt-in companion that compares boost-* family path-repo installs against Packagist (one HTTP call per shadowed package) — surfaces the "stale path repo silently shadows a newer published version" foot-gun after a dogfood window. Routine `boost doctor` stays fully offline (CI-safe).

**First sync is online.** Anonymous GitHub access caps at 60 requests/hour. Set `BOOST_GITHUB_TOKEN` (any token with `public_repo` scope) to lift it to 5000/h — needed for CI runs that hit `withRemoteSkills(...)` cold and for `boost doctor` on > 3 sources, which surfaces the same nudge.

**Trust posture.** Sources and skills are opt-in by full path — declaring `peterfox/agent-skills:composer-upgrade` does not grant access to anything else in that repo. Pin to a tag or SHA in production; moving refs are convenient but a source-side push silently changes what lands. ZIP and tarball extraction reject path-traversal entries, absolute paths, symlinks anywhere in the archive, and oversized payloads (caps: 200 MB total / 50 MB per file / 10000 entries) — a violation rejects the whole source rather than partially extracting.

**Set `BOOST_REMOTE_STRICT=1`** to escalate any remote-source failure (network unreachable, malformed archive, name-mismatch) to a sync-aborting error. Default is warn-and-skip: a failing source records an error in the sync result but the other sources and the rest of the sync proceed.

### Publishing a skill source for remote consumption

Publishing a repo for `withRemoteSkills(...)` consumption: treat the `SKILL.md` frontmatter `name` as a durable public API (renaming breaks moving-ref consumers), keep skill source dirs symlink-free (extraction rejects the whole source on any symlinked entry, including a `LICENSE` symlink at the repo root), and align `metadata.boost-tags` with the family's tag vocabulary (`frontend`, `database`, `php`, etc.) so consumer `withTags()` sets apply uniformly.

## Conditional skill filtering

Vendor skills can be scoped to projects that want them, so a project with no Jira work never receives a `jira-triage` skill (and its `description` never pollutes the agent's skill-selection index).

A skill declares tags in its `SKILL.md` frontmatter, under the Agent Skills standard's `metadata` field:

```yaml
---
name: jira-triage
description: Triage and label incoming Jira issues.
metadata:
  boost-tags: "php jira"
---
```

A project declares the tags it wants in `boost.php`:

```php
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withTags(Tag::Php, Tag::Jira)        // Tag enum cases or raw strings
    ->withExcludedSkills(['acme/pack:unwanted-skill'])
    ->withExcludedGuidelines(['acme/pack:unwanted-guideline']);
```

A vendor skill is synced only when **every** tag in its `boost-tags` is among the project's `withTags()` — `skillTags ⊆ projectTags`. An untagged skill carries the empty set and always ships (so the feature is inert until skills and projects opt in). `withExcludedSkills()` drops a specific `vendor/package:skill-name` regardless of tags.

Vendor **guidelines** filter by the same subset rule, from either of two tag sources: a guideline's own `metadata.boost-tags` frontmatter, or a sidecar `resources/boost/guidelines/.boost-tags.yaml` manifest — a map of guideline filename to a space-delimited tag string. The sidecar exists because a guideline carrying frontmatter is fine for boost-core but not for `laravel/boost`, which renders a `---` block literally; a guideline that must stay frontmatter-free is tagged via the manifest instead, which laravel/boost's `*.md`-only Finder never sees. Frontmatter wins when a guideline has both. An untagged guideline always ships; `withExcludedGuidelines(['vendor/package:guideline-name'])` drops a specific one regardless of tags. Skills always tag inline — `metadata.boost-tags` works in every engine — so the inline-vs-sidecar split is guidelines-only.

The `Tag` enum is a non-authoritative convenience — the tag vocabulary is open, any string is a valid tag; the enum just gives autocomplete for common ones. `vendor/bin/boost tags` lists every tag installed skills and guidelines declare, which of them your `withTags()` currently filters out, and the tags to add to receive them — `boost doctor` carries the same report as one of its sections. When sync drops tagged vendor skills because your `withTags()` is empty, it prints a one-line note pointing at `vendor/bin/boost tags` so the gap surfaces at install time instead of going silent.

`vendor/bin/boost install` includes an interactive tag picker — after the agent + vendor selections, it presents the tags declared by the just-selected vendors (with an "unlocks N skill/guideline" hint per tag) and persists your choice into `withTags(...)`. Tags you've already declared in `boost.php` that no installed vendor publishes are preserved silently across re-installs — the picker controls visible tags only.

`vendor/bin/boost where` complements `tags` — it lists every resolved skill, guideline, and command grouped by origin (`.ai/` host, scanned vendor packages, remote skill sources) under three top-level sections, and annotates host overrides that shadow an allowlisted-vendor copy inline. Answers "where does X come from?", "did my host override the vendor's copy?", and "what will sync write?" in one place. Caller-injected items (the wrapper pattern, e.g. `project-boost-laravel`) are runtime-only inputs to `SyncEngine::sync()` and not visible to `boost where` — wrapper packages own their own inspection surface.

Host-override shadowing is annotated for both **skills and guidelines** (0.13.0) — a host `.ai/guidelines/<name>.md` that overrides an allowlisted-vendor guideline of the same name is flagged inline just like skills, respecting the active `withTags()` filter (a tag-filtered-out vendor guideline isn't "shadowed", so it isn't flagged). `boost doctor` carries the same host-override report.

`vendor/bin/boost where --diff=<name>` follows up on a `(shadows <vendor>)` annotation with the next natural question — *what exactly differs*. Pass a host-shadowed skill OR guideline name (skills matched first) and the command prints a unified diff between the host override and the vendor's upstream copy. A byte-identical pair surfaces a "the override earns nothing — consider removing" hint; when a host guideline shadows the same name across multiple vendors, `--diff` reports the ambiguity (run `boost where` to see all). A missing host or no-vendor match yields a friendly error pointing at `boost where` for the resolved origin map.

> [!WARNING]
> Adding a tag to an **already-shipped** skill is consumer-breaking: every project that has not declared that tag loses the skill. Vendors should treat it as a breaking change (or a loud release-note callout), not a minor tweak.

## CLI reference

| Command                              | Purpose                                                                          | Section                                                     |
|--------------------------------------|----------------------------------------------------------------------------------|-------------------------------------------------------------|
| `boost install`                      | Generate `boost.php` (if missing) + interactive picker for agents, vendors, tags | [Usage](#usage)                                             |
| `boost sync`                         | Fan out skills/guidelines/commands to selected agents                            | [Usage](#usage)                                             |
| `boost sync --check`                 | Dry run — report drift, no writes                                                | [Usage](#usage)                                             |
| `boost sync --scope=user`            | User-scope sync for globally-installed CLI tools                                 | [Usage](#usage)                                             |
| `boost where`                        | Origin-traced listing of every skill / guideline / command that would ship       | [Commands](#commands)                                       |
| `boost where --diff=<name>`          | Shadow diff (skill OR guideline) between vendor source and the version that ships | [Commands](#commands)                                      |
| `boost doctor`                       | Offline health check — config, remote sources, cache state, emitter status       | [Remote skill sources](#remote-skill-sources)               |
| `boost doctor --check-versions`      | Opt-in Packagist comparison for path-repo shadows                                | [Remote skill sources](#remote-skill-sources)               |
| `boost tags`                         | List available tags + their unlock counts across allowlisted vendors             | [Conditional skill filtering](#conditional-skill-filtering) |
| `boost validate`                     | Validate boost.php's `->withConventions([...])` against allowlisted vendors' schemas | [Project Conventions](#project-conventions)             |
| `boost validate --strict`            | Exit non-zero (1) on any error-level diagnostic (CI mode)                        | [Project Conventions](#project-conventions)                 |
| `boost slots`                        | List Project Conventions slots across allowlisted vendors                        | [Project Conventions](#project-conventions)                 |
| `boost slots --missing` / `--filled` | Filter slots by fill state — audit "what have I set"                             | [Project Conventions](#project-conventions)                 |
| `boost convert-conventions`          | One-shot migration: extract CLAUDE.md marker YAML into `boost.php` (0.9.0)       | [Project Conventions](#project-conventions)                 |
| `boost paths`                        | List path globs boost-core manages (vendor-skill-reachable via `--managed`)      | [Project Conventions](#project-conventions)                 |
| `boost doctor --check-conventions`   | Report Project Conventions slot status (missing, unknown, file-existence)        | [Project Conventions](#project-conventions)                 |
| `boost doctor --check-stale-paths`   | Read-only audit of retired-paths registry — what next sync would clean up        | [Remote skill sources](#remote-skill-sources)               |

## Project Conventions

Vendor skills frequently hard-code project context — Jira project keys, branch naming patterns, test framework names. Without a way to inject that context, consumers shadow the skill to override one line, and the maintenance debt explodes.

`boost-core` 0.8.0 adds a JSONSchema-based slot fill-in: vendors declare a `resources/boost/conventions-schema.json` describing the slots they need; consumers declare values in `boost.php` via `->withConventions([...])` (0.9.0+); `boost sync` validates, renders a markerless `## Project Conventions` section into `CLAUDE.md` (0.12.0+), and surfaces missing slots — `boost validate` / `boost slots` / `boost doctor --check-conventions` give the operator-facing audit surface.

Authoring shape (0.9.0+):

```php
// boost.php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withConventions([
        'jira' => ['project_key' => 'HPB'],
        'github' => ['default_base_branch' => 'develop'],
    ]);
```

`boost sync` renders the values from boost.php into `CLAUDE.md`'s `## Project Conventions` section on every sync; `boost.php` is the single source of truth and the section is regenerated wholesale. Conventions render into `CLAUDE.md` whenever they're declared — even if the Claude agent itself isn't in `withAgents(...)`. Diagnostics route through `SyncResult::diagnostics` (lenient channel — never fails sync) and are rendered visibly after `boost sync` / `boost where` primary output.

**Markerless wholesale ownership (0.12.0+).** The agent-guidance files (CLAUDE.md, AGENTS.md, GEMINI.md) are wholesale boost-owned and carry **no markers** — boost-core regenerates them in full on every sync from `.ai/guidelines/` + `boost.php`. Operator-authored guidance belongs in `.ai/guidelines/` (it's assembled into the file on every sync), not hand-edited into the emission target. On the first 0.12.0 sync of a legacy marker-bounded file, the markers are stripped and any genuine out-of-marker content is preserved once below the generated body with a warning pointing at `.ai/guidelines/`.

**Ownership manifest (0.13.0+).** boost-core records what it emits in a gitignored `.boost/manifest.json` (path → sha + provenance). This is the ownership signal the markerless model otherwise lacks: with it, sync can safely *converge* a boost-owned guidance file to empty when all guidance is removed, while still **never** touching operator content — a file you've hand-edited (its sha no longer matches the manifest) is preserved, not blanked. The manifest is regenerable emit-state (gitignored, never committed); absent it, sync falls back to exact pre-0.13 behavior. Decisions read the *prior* manifest and the new one is written only after a fully successful sync, so a render failure or partial run never corrupts ownership.

**Empty-assembly guard (0.12.0+).** Sync never blanks a non-empty guidance file it can't prove it owns: if boost resolves no guidelines and no conventions, an existing non-empty CLAUDE.md/AGENTS.md/GEMINI.md that isn't manifest-owned (or is sha-diverged) is left untouched (an INFO records this) rather than overwritten — so adopting boost-core in a repo that already has a hand-written CLAUDE.md never wipes it. Delete the file manually if you want it empty. (Legacy marker-bounded files are exempt — they're provably boost-written, so they still converge.)

**Tracked guidance files.** CLAUDE.md / AGENTS.md / GEMINI.md should be tracked in version control so boost-core's wholesale output is reviewable in diffs and recoverable from git. Boost-core's managed `.gitignore` block keeps these files out of the ignore list. Skill + command directories stay gitignored — those are 100% generated from `.ai/` content.

**Migration from 0.8.x marker conventions.** Projects that still carry a `boost-core:conventions:*` marker block can migrate the YAML into `boost.php` with `vendor/bin/boost convert-conventions` (run it *before* upgrading to 0.12.0, while the markers still exist). After a 0.12.0 sync has stripped the markers, copy any preserved conventions YAML into `boost.php`'s `->withConventions([...])` chain by hand — `convert-conventions` requires the markers and no longer applies. `boost.php` is canonical thereafter.

For schema authors, see the spec in `internal/specs/conventions-schema.md` (gitignored, branch-local).

## Testing

```bash
composer test
```

That runs the full Pest suite (unit + integration, including real `composer install` subprocesses for the standalone-bin and end-user-install surfaces). Coverage report via `composer test-coverage`.

## Upgrading

See [`UPGRADING.md`](UPGRADING.md) for breaking-change migrations between majors/minors.

> [!NOTE]
> The `FileEmitter` plugin contract is `@experimental` — the shape will change before v1.0 stable.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the full release history. The GitHub [releases page](https://github.com/sandermuller/boost-core/releases) has per-version notes.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for development setup, test conventions, and the pre-release gauntlet.

## Security

If you find a security issue, please email `github@scode.nl` rather than filing a public issue. See [`SECURITY.md`](SECURITY.md) for the disclosure policy.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All contributors](https://github.com/sandermuller/boost-core/contributors)

Heavy inspiration from [`laravel/boost`](https://github.com/laravel/boost) — this is its framework-free sibling.

## License

MIT. See [LICENSE](LICENSE).
