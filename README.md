# boost-core

> AI agent configuration sync for PHP projects. Write skills and guidelines once in `.ai/`; boost-core publishes them to nine agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). No framework dependency, and vendor skills sync only from an allowlist you control.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/boost-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/boost-core/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-core.svg?style=flat-square)](LICENSE)

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
composer boost:install   # generate boost.php (if missing) + interactive picker for agents + vendor allowlist
composer boost:sync      # fan out to selected agents
```

After install, every `composer install` / `composer update` re-runs `boost:sync` automatically (post-autoload-dump). Set `BOOST_SKIP_AUTOSYNC=1` to disable.

For tooling authors who want to publish their own skills to every AI agent on the user's machine:

```bash
composer boost:sync --scope=user   # ~/.{agent}/skills/<vendor>__<package>/<skill>/SKILL.md
```

In `composer global` context (`composer global require <skill-bearing-package>`), the plugin auto-detects the global install and runs user-scope sync for every globally-installed package shipping `resources/boost/skills/`, so no manual `--scope=user` is needed. Each package's paths are namespaced by its full `vendor/package` slug, with `/` replaced by `__`. That sequence can't occur inside a Composer package name, so two packages never produce the same slug — `vendor-a/foo` and `vendor-b/foo` land in separate directories.

### Composer script callback (for plugin-package authors)

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

It checks `Event::isDevMode()`, resolves `composer config.bin-dir`, runs `vendor/bin/boost sync` and surfaces non-zero exits through Composer's IO. Works on Windows + Unix. Honors `BOOST_SKIP_AUTOSYNC=1` (same escape hatch as the plugin's auto-sync hook). The plugin's `onPostAutoloadDump` already runs sync for end-user installs — this callback is for plugin packages in the `boost-*` family that want an explicit, cross-platform script entry of their own.

For user-invoked scripts (`composer sync-ai`, etc.) where silence on success reads as a no-op, use `BoostAutoSync::runWithSummary` instead — same behaviour but streams the binary's one-line success summary through Composer's IO:

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

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
    ->withExcludedSkills(['acme/pack:unwanted-skill']);
```

A vendor skill is synced only when **every** tag in its `boost-tags` is among the project's `withTags()` — `skillTags ⊆ projectTags`. An untagged skill carries the empty set and always ships (so the feature is inert until skills and projects opt in). `withExcludedSkills()` drops a specific `vendor/package:skill-name` regardless of tags.

The `Tag` enum is a non-authoritative convenience — the tag vocabulary is open, any string is a valid tag; the enum just gives autocomplete for common ones. `composer boost:tags` lists every tag installed skills declare, which skills your `withTags()` currently filters out, and the tags to add to receive them — `boost:doctor` carries the same report as one of its sections.

> [!WARNING]
> Adding a tag to an **already-shipped** skill is consumer-breaking: every project that has not declared that tag loses the skill. Vendors should treat it as a breaking change (or a loud release-note callout), not a minor tweak.

## Testing

```bash
composer test
```

That runs the full Pest suite (unit + integration, including real `composer install` subprocesses for the standalone-bin, plugin-capability, and global-context surfaces). Coverage report via `composer test-coverage`.

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
