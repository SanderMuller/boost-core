# boost-core

> AI agent configuration sync for PHP projects. Author skills and guidelines once in `.ai/`, publish to nine agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). Framework-free PHP, allowlist-based vendor trust, Rector-style explicit commands.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![Tests](https://img.shields.io/github/actions/workflow/status/sandermuller/boost-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/boost-core/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-core.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-core)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-core.svg?style=flat-square)](LICENSE)

## Install

You don't usually install `boost-core` directly — it comes in as a dep of one of the bundle packages that match your role:

```bash
# PHP application developer
composer require --dev sandermuller/project-boost

# Laravel application developer — use the original, this family doesn't replace it
composer require --dev laravel/boost

# Framework-agnostic Composer package author
composer require --dev sandermuller/package-boost-php

# Laravel package author
composer require --dev sandermuller/package-boost-laravel
```

Direct install for tooling authors who want to ship their own skill bundle:

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
composer boost:sync --scope=user   # ~/.{agent}/skills/<package>/<skill>/SKILL.md
```

In `composer global` context (`composer global require <skill-bearing-package>`), the plugin auto-detects the global install and runs user-scope sync for every globally-installed package shipping `resources/boost/skills/` — no manual `--scope=user` invocation required. If two globally-installed packages share a basename (`vendor-a/foo` + `vendor-b/foo`), the first one syncs and the second is skipped with a warning naming the conflict.

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

It checks `Event::isDevMode()`, resolves `composer config.bin-dir`, runs `vendor/bin/boost sync` and surfaces non-zero exits through Composer's IO. Works on Windows + Unix. The plugin's `onPostAutoloadDump` already runs sync for end-user installs — this callback is for plugin packages in the `boost-*` family that want an explicit, cross-platform script entry of their own.

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
