# boost-core

> AI agent configuration sync for PHP projects. Write skills, guidelines, and commands once in `.ai/`; boost-core publishes them to nine agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). No framework dependency, and vendor skills sync only from an allowlist you control.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/boost-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/boost-core/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-core.svg?style=flat-square)](LICENSE)
[![Laravel Boost](https://badge.laravel.cloud/boost-badge.svg?style=flat-square)](https://github.com/laravel/boost)

## Install

`boost-core` is the engine. You rarely install it directly — you install the family package that matches what you're building, and it pulls `boost-core` in.

| You're building                          | Install                                                                                       | Ships                                                                                      |
|------------------------------------------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| A PHP application (not a package)        | [`sandermuller/project-boost`](https://github.com/sandermuller/project-boost)                 | App-dev skills — DDD layering, repository pattern, DI, domain modeling, legacy coexistence |
| A Laravel application                    | [`laravel/boost`](https://github.com/laravel/boost)                                           | The original — this family doesn't replace it                                              |
| A framework-agnostic Composer package    | [`sandermuller/package-boost-php`](https://github.com/sandermuller/package-boost-php)         | Package-author skills + `lean` / `gitattributes` commands                                  |
| A Laravel package                        | [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel) | Laravel-package skills + `McpJsonEmitter`                                                  |
| Your own skill bundle, or custom tooling | `sandermuller/boost-core` directly                                                            | Just the sync engine — you supply the skills                                               |

```bash
composer require --dev sandermuller/boost-core
```

## Usage

```bash
vendor/bin/boost install   # generate boost.php (if missing) + interactive picker for agents + vendor allowlist
vendor/bin/boost sync      # fan out to selected agents
```

boost-core is a plain library — it runs no install-time code of its own. To re-sync automatically after a `composer install` / `composer update`, wire the `BoostAutoSync` script callback into your project's `composer.json` (see below); otherwise run `vendor/bin/boost sync` yourself, e.g. in CI. `BOOST_SKIP_AUTOSYNC=1` disables the callback.

For tooling authors who want to publish their own skills to every AI agent on the user's machine:

```bash
vendor/bin/boost sync --scope=user   # ~/.{agent}/skills/<vendor>__<package>/<skill>/SKILL.md
```

After `composer global require`-ing one or more skill-bearing packages, run `vendor/bin/boost sync --scope=user --all` once — it user-scope-syncs every globally-installed package that ships `resources/boost/skills/`. User scope publishes a package's skills **wholesale**: there is no `boost.php` in play, so tag filters (`withTags()`) and the vendor allowlist — both project-scope controls — do not apply. Each package's paths are namespaced by its full `vendor/package` slug, with `/` replaced by `__`. That sequence can't occur inside a Composer package name, so two packages never produce the same slug — `vendor-a/foo` and `vendor-b/foo` land in separate directories.

### Auto-sync on `composer install`

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

For user-invoked scripts (`composer sync-ai`, etc.) where silence on success reads as a no-op, use `BoostAutoSync::runWithSummary` instead — it streams the binary's one-line success summary on *every* successful sync, including the no-op installs `run` keeps quiet:

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

### Self-sync for globally-installed CLI tools

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

| Agent       | Command target                                  |
| ----------- | ----------------------------------------------- |
| Claude Code | `.claude/commands/`                             |
| Cursor      | `.cursor/commands/`                             |
| Copilot     | `.github/prompts/` (as `<name>.prompt.md`)      |
| Junie       | `.junie/commands/`                              |
| OpenCode    | `.opencode/commands/`                           |
| Amp         | `.agents/commands/`                             |
| Kiro        | `.kiro/skills/<name>/SKILL.md` (slash-command)  |

Kiro has no dedicated command directory — its committed `.kiro/skills/` is the slash-command surface, so a `.ai/commands/<name>.md` lands as `.kiro/skills/<name>/SKILL.md`.

Codex and Gemini have no committable target boost-core can write into — Codex's prompts (`~/.codex/prompts/`) are deprecated and personal-only, Gemini uses TOML. When `.ai/commands/` is populated and one of those agents is in `withAgents()`, `boost doctor` prints the manual authoring path so the gap isn't silent. The source directory defaults to `.ai/commands`; override it with `->withCommandsPath(...)` in `boost.php`.

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

[`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) is built this way: a package of skills and nothing else, several of them tagged for conditional sync (see below).

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
        RemoteSkillSource::githubPath('blader/humanizer', 'v0.3.0', [
            'humanizer' => '.',
        ]),
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

If you publish a repo intended for `withRemoteSkills(...)` consumption:

- Treat the `SKILL.md` frontmatter `name` as a **durable public API surface**. Renaming it breaks every moving-ref consumer the moment they re-sync.
- **Keep skill source dirs symlink-free**, including project-metadata symlinks like a `LICENSE` symlink at the repo root. Extraction rejects the whole source on any symlinked entry — the file-inclusion filter would drop benign symlinks anyway, but the reject-on-symlink rule fires first.
- **Align tag vocabulary with established conventions** in `metadata.boost-tags` (`frontend` for JS/TS toolchain, `database` for projects with a database, `php`, etc.) — common tags collide semantically across sources and a consumer's single `withTags()` set applies uniformly.

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

`vendor/bin/boost where` complements `tags` — it lists every resolved skill, guideline, and command grouped by origin (`.ai/` host, scanned vendor packages, remote skill sources) under three top-level sections, and annotates host overrides that shadow an allowlisted-vendor copy inline. Answers "where does X come from?", "did my host override the vendor's copy?", and "what will sync write?" in one place. Caller-injected items (the wrapper pattern, e.g. `project-boost-laravel`) are runtime-only inputs to `SyncEngine::sync()` and not visible to `boost where` — wrapper packages own their own inspection surface.

> [!WARNING]
> Adding a tag to an **already-shipped** skill is consumer-breaking: every project that has not declared that tag loses the skill. Vendors should treat it as a breaking change (or a loud release-note callout), not a minor tweak.

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
