<!-- AI agents: read llms.txt for a structured overview, and llms-install.md for the step-by-step install guide. -->
# boost-core

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/boost-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/boost-core/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-core.svg?style=flat-square)](LICENSE)
[![Laravel Boost](https://badge.laravel.cloud/boost-badge.svg?style=flat-square)](https://github.com/laravel/boost)

> AI agent configuration sync for any PHP project. Write skills, guidelines, and commands once in `.ai/`; boost-core publishes them to nine agents: Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp. No framework dependency.

![overview image](overview.png)

## Contents

- [How sync works](#how-sync-works) · [Install](#install) · [Quickstart](#quickstart) · [What you get](#what-you-get)
- [Skill sources](#skill-sources) · [Tag filtering](#tag-filtering) · [Commands](#commands) · [Skill rendering](#skill-rendering)
- [Automating the sync](#automating-the-sync) · [Project Conventions](#project-conventions) · [File ownership](#file-ownership)
- [CLI reference](#cli-reference) · [Environment variables](#environment-variables) · [Versioning & stability](#versioning--stability)

## How sync works

You author three kinds of content under `.ai/`, and `boost sync` fans each out to
every agent you selected in `withAgents(...)`. One source, many agent-native copies:

| You write in      | What it is                       | `boost sync` fans it out to                         |
|-------------------|----------------------------------|-----------------------------------------------------|
| `.ai/skills/`     | Agent Skills (`<name>/SKILL.md`) | `.{agent}/skills/<name>/SKILL.md` per agent         |
| `.ai/guidelines/` | Always-loaded guidance           | `CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, Copilot file |
| `.ai/commands/`   | Slash-command prompt templates   | Per-agent command dirs (see [Commands](#commands))  |

Skills and commands land in gitignored per-agent directories; the guidance files
stay tracked. See [File ownership](#file-ownership) for why.

## Install

`boost-core` is the engine. You rarely install it directly. Instead you install
the **family package** (a thin wrapper that bundles boost-core with a curated
skill set) that matches what you're building, and it pulls `boost-core` in.

### Let your AI agent install it

Don't want to pick? Paste this prompt to your coding agent from the repo root —
it picks the right family member, installs it, configures agents + tags, and
verifies. Nothing installs until it runs `composer require`:

```text
Install the boost AI-config toolkit in this repository. Read
https://raw.githubusercontent.com/sandermuller/boost-core/main/llms-install.md
and follow it exactly: inspect the repo, pick the single best-fit family member,
install it, and configure boost.php for my stack — the agents I use and matching
tags. Then run the first sync, verify, and tell me what you installed, why, how
it works, and any follow-ups.
```

Prefer to choose yourself? Use the table below.

| You're building                       | Install                                                                                       | Ships                                                                                      |
|---------------------------------------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| A PHP application (not a package)     | [`sandermuller/project-boost-php`](https://github.com/sandermuller/project-boost-php)         | App-dev skills — dependency injection, legacy coexistence + the `foundation` guideline      |
| A Laravel application                 | [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) | `laravel/boost` MCP coexistence + nine-agent fanout + tag filter + remote skills           |
| A framework-agnostic Composer package | [`sandermuller/package-boost-php`](https://github.com/sandermuller/package-boost-php)         | Package-author skills + `lean` / `gitattributes` commands                                  |
| A Laravel package                     | [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel) | Laravel-package skills + `McpJsonEmitter`                                                  |
| **Your own skill bundle / tooling**   | **`sandermuller/boost-core` directly**                                                        | **Just the sync engine — you supply the skills  ← you are here**                           |

Most users install a wrapper from the table above. Only when you want the bare
engine — the last row, where you supply your own skills — install `boost-core`
directly:

```bash
composer require --dev sandermuller/boost-core
```

Coexists with [`laravel/boost`](https://github.com/laravel/boost) in Laravel
projects via [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel).

![family overview image](overview_family.png)

## Quickstart

```bash
vendor/bin/boost install   # scaffold boost.php + pick agents, vendor allowlist, tags
vendor/bin/boost sync      # fan out to selected agents
vendor/bin/boost sync --check   # dry run — report drift, no writes
```

Config lives at `boost.php` (repo root) or `.config/boost.php`; see
[File ownership](#file-ownership) for the layout details. A minimal config:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::CURSOR,
    ]);
```

boost-core is a plain library and runs no install-time code of its own. Run
`vendor/bin/boost sync` yourself (e.g. in CI), or wire the
[autosync hook](#automating-the-sync) to re-sync on `composer install`.

## What you get

|                          | `laravel/boost`          | `boost-core`                                                                                                         |
|--------------------------|--------------------------|----------------------------------------------------------------------------------------------------------------------|
| Framework scope          | Laravel only             | **Any PHP** (Laravel, Symfony, plain-PHP, packages)                                                                  |
| Skill sources            | bundled + `.ai/skills/`  | `.ai/skills/` + Composer packages (`resources/boost/skills/`) + `withRemoteSkills()` + `withAllowedVendors()` filter |
| Tag filtering            | none                     | `withTags()` subset rule                                                                                             |
| Remote skill sources     | none                     | `withRemoteSkills()` — GitHub bundles + path imports                                                                 |
| User-scope sync          | none                     | `boost sync --scope=user` for globally-installed CLI tools                                                           |
| Origin tracing           | none                     | `boost where` + `boost where --diff=<name>` (host / vendor / remote / shadow)                                        |
| Doctor / path-repo audit | none                     | `boost doctor`, `boost doctor --check-versions`                                                                      |
| `.ai/commands/` fan-out  | none                     | per-agent argument transpilation across 7 emit targets                                                               |
| Project Conventions      | none                     | JSONSchema-validated slot fill-in via `boost validate` / `boost slots`                                               |

The MCP server (Model Context Protocol) + Laravel docs API are `laravel/boost`'s domain, so boost-core defers
to them in Laravel projects (see
[`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)
for coexistence).

## Skill sources

Skills come from three places, all resolved on the same `composer install / update`
lifecycle and fanned out side by side:

1. **Host** — your project's own `.ai/skills/`.
2. **Vendor packages** — any Composer package that ships
   `resources/boost/skills/<name>/SKILL.md`. Allowlist the vendor to pick it up:

   ```php
   return BoostConfig::configure()
       ->withAllowedVendors(['vendor/package'])
       ->withAgents([Agent::CLAUDE_CODE]);
   ```

   This is how a team distributes one curated skill set across many repos: author
   once in a package, allowlist everywhere.
   [`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) is
   one example of the pattern.
3. **Remote sources** — GitHub repos shipping `.skill` release bundles or skill
   subdirs, declared with `withRemoteSkills()` (below).

### Remote skill sources

```php
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withRemoteSkills([
        // Bundle mode — fetch the named `.skill` release asset and unzip it.
        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
            'composer-upgrade',
            'phpstan-developer',
        ]),

        // Path mode — fetch the repo tarball at a ref and extract named subdirs.
        // `.` covers a whole-repo-is-one-skill layout.
        RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]),
    ]);
```

Each fetched skill fans out exactly like host and vendor skills: same layout,
same `withTags()` filtering, same `withExcludedSkills(['<owner>/<repo>:<name>'])`
deny-list. Removing an entry prunes its output on the next sync.

- **Cache.** Bundles and tarballs land under
  `${BOOST_CACHE_HOME:-${XDG_CACHE_HOME:-$HOME/.cache}}/boost/remote-skills/`.
  Pinned refs (a tag or 40-char SHA) cache forever; moving refs (`main`, a branch)
  re-resolve every 24h.
- **Offline.** `boost sync --check` never hits the network. `boost doctor` lists
  every source, flags moving refs, and reports cache presence.
- **Rate limit.** Anonymous GitHub caps at 60 req/h. Set `BOOST_GITHUB_TOKEN`
  (any token with `public_repo` scope) to lift it to 5000/h. Cold CI runs and
  `boost doctor` over many sources need it.
- **Trust.** Sources are opt-in by full path: `peterfox/agent-skills:composer-upgrade`
  grants access to nothing else in the repo. Pin to a tag or SHA in production;
  moving refs are convenient, but a source-side push silently changes what lands.
  Archive extraction rejects path traversal, absolute paths, symlinks, and
  oversized payloads (200 MB total / 50 MB per file / 10000 entries), and any
  violation rejects the whole source rather than extracting part of it.
- **Strict mode.** `BOOST_REMOTE_STRICT=1` escalates any source failure to a
  sync-aborting error. Default is warn-and-skip.

**Publishing a source** for remote consumption: treat the `SKILL.md` frontmatter
`name` as durable public API (renaming breaks moving-ref consumers), keep source
dirs symlink-free (extraction rejects any symlinked entry), and align
`metadata.boost-tags` with the family's tag vocabulary.

## Tag filtering

Vendor skills can be scoped to projects that want them, so a project with no Jira
work never receives a `jira-triage` skill (and its `description` never pollutes
the agent's skill-selection index).

A skill declares tags in its `SKILL.md` frontmatter:

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
    ->withTags([                             // Tag enum cases or raw strings
        Tag::Php,
        Tag::Jira,
    ])
    ->withExcludedSkills(['acme/pack:unwanted-skill'])
    ->withExcludedGuidelines(['acme/pack:unwanted-guideline']);
```

**The rule:** a vendor skill ships only when *every* tag in its `boost-tags` is
among the project's `withTags()` (`skillTags ⊆ projectTags`). An untagged skill
always ships, so the feature is inert until skills and projects opt in.
`withExcludedSkills()` drops a specific `vendor/package:skill-name` regardless of
tags. Vendor **guidelines** filter the same way, tagged either by `metadata.boost-tags`
or a sidecar `resources/boost/guidelines/.boost-tags.yaml` manifest (for guidelines
that must stay frontmatter-free for `laravel/boost`).

The `Tag` enum is just a convenience; the vocabulary is open, and any string is a
valid tag. `vendor/bin/boost tags` lists every tag installed skills/guidelines declare,
which your `withTags()` filters out, and what to add to receive them;
`boost install`'s interactive picker offers the same. When sync drops tagged skills
because `withTags()` is empty, it prints a one-line nudge at `boost tags`.

> [!WARNING]
> Adding a tag to an **already-shipped** skill is consumer-breaking: every project
> that hasn't declared that tag loses the skill. Treat it as a breaking change.

Use `boost where` to trace where every resolved skill, guideline, and command
comes from (host / vendor / remote), with host-override shadowing annotated
inline. `boost where --diff=<name>` prints a unified diff between a host override
and the vendor copy it shadows.

## Commands

`.ai/commands/*.md` holds reusable prompt templates: the slash-command files
agents surface in their palette. `boost sync` fans each out to the seven agents
with a command surface:

| Agent       | Command target                                 |
|-------------|------------------------------------------------|
| Claude Code | `.claude/commands/`                            |
| Cursor      | `.cursor/commands/`                            |
| Copilot     | `.github/prompts/` (as `<name>.prompt.md`)     |
| Junie       | `.junie/commands/`                             |
| OpenCode    | `.opencode/commands/`                          |
| Amp         | `.agents/commands/`                            |
| Kiro        | `.kiro/skills/<name>/SKILL.md` (slash-command) |

Codex and Gemini have no committable command target boost-core can write into
(Codex's prompts are deprecated/personal-only, Gemini uses TOML). When
`.ai/commands/` is populated and one of those agents is selected, `boost doctor`
prints the manual authoring path so the gap isn't silent. Override the source dir
with `->withCommandsPath(...)`.

**Argument placeholders are transpiled per-agent.** Author once using the canonical
syntax: `$ARGUMENTS` (unsplit), `$1`/`$2`/… (positional), `$name` (named, optionally
declared in frontmatter `arguments:`), and `\$` escapes for literals. On sync,
boost-core converts each to the agent's native shape (Claude `$0`-indexed, Copilot
`${input:…}`, and so on). Cursor and Amp have no placeholder support and emit
verbatim with a warning. The bundled `command-arguments` skill documents the full
table.

## Skill rendering

Skill files default to plain markdown (`SKILL.md`). For template-flavored content
(Blade, Twig, anything needing a render step), register a `SkillRenderer` in
`boost.php`:

```php
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withSkillRenderers([new BladeRenderer]);
```

The dispatcher matches longest-extension-first, so a `BladeRenderer` claiming
`blade.php` handles `SKILL.blade.php`. The built-in `PassthroughRenderer` always
handles `.md`. Render failures default to warn-and-skip (recorded in
`SyncResult::errors`); `BOOST_RENDER_STRICT=1` escalates the first failure to a
sync-aborting error. A source whose extension has **no** registered renderer
(e.g. a `SKILL.blade.php` with no `BladeRenderer`) is flagged by `boost sync` and
`boost doctor`. Register a renderer for it, or rename it to `SKILL.md`.

The `SkillRenderer` contract is `@api` (locked at 1.0). Plugin authors writing
renderers, `FileEmitter`s, or a `BoostWrapperContract` should work from
[`PUBLIC_API.md`](PUBLIC_API.md), which pins the frozen contract surface.

## Automating the sync

boost-core ships no Composer plugin, so a `composer install` re-sync is opt-in.
Pick the entry point that fits:

| Entry point                        | Use for                                                             |
|------------------------------------|---------------------------------------------------------------------|
| `BoostAutoSync::run`               | `post-install-cmd` / `post-update-cmd` hooks — silent on a no-op    |
| `BoostAutoSync::runWithSummary`    | User-invoked scripts (`composer sync-ai`) — prints a summary always |
| `BoostAutoSync::syncUserScopeOnce` | A globally-installed CLI tool self-syncing its own bundled skills   |

All three honor `BOOST_SKIP_AUTOSYNC=1`.

### Composer hook (consumer project)

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

`run` checks `Event::isDevMode()`, resolves the bin-dir, runs `vendor/bin/boost sync`,
surfaces non-zero exits through Composer's IO, and is **silent on a true no-op**
(`wrote=0, deleted=0`). Output appears only when something changed or errored.
`runWithSummary` prints the one-line success summary on *every* sync, including
the no-ops `run` keeps quiet (useful when debugging "did the hook fire?"). Both
work on Windows + Unix.

> [!IMPORTANT]
> **On Laravel + [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)**,
> use `@php artisan project-boost:sync` instead of `BoostAutoSync::run`. The
> artisan path runs through the Laravel container, which bootstraps `BladeRenderer`
> and delivers laravel/boost's bundled skills to every agent. The bare-CLI path
> bypasses both. See `project-boost-laravel`'s install guide for the canonical
> `scripts` shape.

### CLI tool you publish (self-sync from bin script)

A tool installed with `composer global require` keeps its own bundled skills
current by self-syncing from its bin script:

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

`syncUserScopeOnce()` runs a user-scope sync the first time it sees a given
version, then writes a per-version sentinel so later runs are free.
`syncUserScope()` is the ungated form. Both never throw, so the tool keeps running
even if its sync fails.

After `composer global require`-ing skill-bearing packages, run
`vendor/bin/boost sync --scope=user --all` once to user-scope-sync every globally
installed package that ships `resources/boost/skills/`, into
`~/.{agent}/skills/<vendor>__<package>/<skill>/SKILL.md`. User scope publishes a
package's skills **wholesale**: there's no `boost.php`, so tag filters and the
vendor allowlist (both project-scope controls) don't apply. Removed packages are
reaped on the next `--all` run; see [File ownership](#file-ownership).

## Project Conventions

Vendor skills often need project-specific context: a Jira key, a branch pattern,
a test runner. Project Conventions injects it via a JSONSchema slot fill-in.
Vendors declare slots in `resources/boost/conventions-schema.json`; consumers fill
them in `boost.php`:

```php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withConventions([
        'jira' => ['project_key' => 'HPB'],
        'github' => ['default_base_branch' => 'develop'],
    ]);
```

Skills consume a slot either with an inline `<!--boost:conv path="…" mode="…"-->`
token (resolved into the emitted file) or via the rendered `## Project Conventions`
block in `CLAUDE.md`. `boost validate --strict` hard-fails CI on a leaked token;
`boost slots` / `boost where --conventions` audit what's set.

**See [`docs/conventions.md`](docs/conventions.md)** for the full reference:
inline tokens, the paired visible-default form (survives resolver-less engines like
`laravel/boost`), observability, legacy-ref migration, and migrating vendor skills.

## File ownership

boost-core generates files into your repo and home directory and tracks what it
owns in a manifest, so a sync never silently overwrites hand-written content:

- **Guidance files** (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, Copilot) are wholesale
  boost-owned, markerless, and regenerated from `.ai/guidelines/` on every sync,
  but kept **tracked** so output is reviewable in diffs. Author guidance in
  `.ai/guidelines/`, never by hand-editing the target.
- **Skill + command directories** are gitignored (100% generated from `.ai/`).
- A file you've hand-edited (sha diverged from the manifest) is **never** blanked
  or reaped. Adopting boost-core in a repo with an existing `CLAUDE.md` won't wipe it.
- Removing a vendor dep or de-selecting an agent reaps the now-orphaned files it owned.

**See [`docs/file-ownership.md`](docs/file-ownership.md)** for the manifest,
lifecycle reap, the empty-assembly guard, `.config/` layout + relocation, managed
`.gitignore`, and user-scope cleanup-on-remove.

## CLI reference

| Command                              | Purpose                                                                                                                              |
|--------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `boost install`                      | Scaffold `boost.php` (if missing) + interactive agent / vendor / tag picker                                                          |
| `boost sync`                         | Fan out skills / guidelines / commands to selected agents                                                                            |
| `boost sync --check`                 | Dry run — report drift, no writes (offline; gate CI on this)                                                                         |
| `boost sync --scope=user [--all]`    | User-scope sync for globally-installed CLI tools                                                                                     |
| `boost where`                        | Origin-traced listing of every skill / guideline / command that would ship                                                           |
| `boost where --diff=<name>`          | Unified diff (skill OR guideline) between a host override and the vendor copy                                                        |
| `boost where --conventions [--json]` | Effective resolved conventions slots + provenance + block keep/drop status                                                           |
| `boost doctor`                       | Offline health check — config, remote sources, cache, emitters, token leaks. **Advisory only** — exits 0 unless config fails to load |
| `boost doctor --check-versions`      | Opt-in Packagist comparison for path-repo shadows (one HTTP call per package)                                                        |
| `boost doctor --check-conventions`   | Report conventions slot status (missing, unknown, file-existence)                                                                    |
| `boost doctor --check-stale-paths`   | Read-only audit of the retired-paths registry — what the next sync would clean up                                                    |
| `boost tags`                         | List available tags + their unlock counts across allowlisted vendors                                                                 |
| `boost validate [--strict]`          | Validate `withConventions([...])` + scan for leaked tokens (`--strict` fails CI)                                                     |
| `boost slots [--missing\|--filled]`  | List conventions slots, optionally filtered by fill state                                                                            |
| `boost paths`                        | List path globs boost-core manages                                                                                                   |
| `boost convert-conventions`          | Legacy one-shot: extract 0.8.x marker YAML into `boost.php` (hidden, not a contract)                                                 |

Exit codes: `0` ok, `1` failure, `2` usage. `boost doctor` is advisory, so gate CI
on `sync --check` / `validate --strict` instead.

## Environment variables

Every variable is opt-in; unset = default behavior.

| Variable                 | Effect                                                                                     |
|--------------------------|--------------------------------------------------------------------------------------------|
| `BOOST_SKIP_AUTOSYNC=1`  | Skip the `BoostAutoSync` composer-hook sync entirely                                       |
| `BOOST_SKIP_GITIGNORE=1` | Skip managed `.gitignore` updates (handy for CI / ephemeral Docker installs)               |
| `BOOST_GITHUB_TOKEN`     | GitHub token (`public_repo` scope) — lifts remote-skill fetches from 60 to 5000 req/h      |
| `BOOST_REMOTE_STRICT=1`  | Escalate any remote-skill source failure to a sync-aborting error (default: warn-and-skip) |
| `BOOST_RENDER_STRICT=1`  | Escalate the first skill-render failure to a sync-aborting error (default: warn-and-skip)  |
| `BOOST_CACHE_HOME`       | Override the remote-skill cache root (defaults to `$XDG_CACHE_HOME` / `~/.cache`)          |

## Versioning & stability

boost-core follows [Semantic Versioning](https://semver.org). The promise covers
the **public surface** only:

- **Config authoring API** — `BoostConfig::configure()`, the `BoostConfigBuilder`
  `with*()` methods, the `Agent` / `Tag` enums, and `RemoteSkillSource`.
- **CLI** — command names, documented options, and exit codes.
- **Composer hooks** — `BoostAutoSync::run` / `runWithSummary` (new parameters
  always optional-with-default).
- **Plugin contracts** — `FileEmitter`, `SkillRenderer`, `BoostWrapperContract`
  and their DTOs. Parameterless constructors only.

Everything marked `@internal` (the whole engine) and on-disk regenerable state
(the sync manifest, ledgers, runtime dirs) may change in any release. The full
committed surface is enumerated in [`PUBLIC_API.md`](PUBLIC_API.md). From `1.0.0`
on, breaking changes land only in a MAJOR bump and are called out in
[`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md).

## More

- [`llms.txt`](llms.txt) — structured overview for AI agents (what this package is, key docs)
- [`llms-install.md`](llms-install.md) — step-by-step install guide an agent can execute
- [`UPGRADING.md`](UPGRADING.md) — breaking-change migrations between versions
- [`CHANGELOG.md`](CHANGELOG.md) — full release history ([releases page](https://github.com/sandermuller/boost-core/releases) has per-version notes)
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — dev setup, test conventions, pre-release gauntlet
- [`PUBLIC_API.md`](PUBLIC_API.md) — the frozen semver surface in full

### Testing

```bash
composer test            # full Pest suite (unit + integration, real composer-install subprocesses)
composer test-coverage   # with coverage report
```

## Security

Email security issues to `github@scode.nl` rather than filing a public issue. See
[`SECURITY.md`](SECURITY.md) for the disclosure policy.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All contributors](https://github.com/sandermuller/boost-core/contributors)

Heavily inspired by [`laravel/boost`](https://github.com/laravel/boost). It's the
framework-free sibling.

## License

MIT. See [LICENSE](LICENSE).
