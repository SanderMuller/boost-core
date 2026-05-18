# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.1.2...HEAD)

### Added

- **`BoostCorePlugin` now auto-syncs user-scope skills in `composer global` context.** When Composer runs under `composer global <cmd>` (detected via `composer->getConfig()->get('home') === cwd` AND `argv` contains `global`), the plugin iterates every globally-installed package, finds those shipping `resources/boost/skills/`, and syncs each into the agents' home-directory skill folders (`~/.claude/skills/{package}/`, etc.) — equivalent to `vendor/bin/boost sync --scope=user` per package. Lets packages like `sandermuller/repo-init` drop their own post-install scripts; installing them via `composer global require` is enough. `BOOST_SKIP_AUTOSYNC=1` bypasses, same as project-scope auto-sync.

### Fixed

- **Standalone `bin/boost` no longer fatals in end-user (non-dev) installs.** `BoostCoreCommandProvider` implements `Composer\Plugin\Capability\CommandProvider` and `BoostBaseCommand` extended `Composer\Command\BaseCommand` — both classes only exist in vendor/ when `composer/composer` is dev-installed. End users running `vendor/bin/boost` got `Interface "Composer\Plugin\Capability\CommandProvider" not found`. Standalone bin now consumes a new `CommandRegistry` (zero Composer deps) and `BoostBaseCommand` extends `Symfony\Console\Command\Command` directly. Composer plugin path is unchanged.

### Added

- Initial scaffolding via `sandermuller/repo-init` (php-package category, customized to `composer-plugin` type).
- Composer plugin entry (`BoostCorePlugin`) registering a `CommandProvider` for future `boost:*` commands.
- Standalone `bin/boost` entry script for direct invocation outside Composer.
- `FileEmitter` plugin contract sketch (`@experimental`) — see `internal/boost-file-emitter-contract.md` in the design repo.

> **Note:** `0.1.0` and `0.1.1` ship a broken standalone `bin/boost` for end-user installs (fatal at startup, as above). Use `0.1.2`+ if you invoke `vendor/bin/boost` directly. The `composer boost:*` plugin path is unaffected.

## [0.1.2](https://github.com/sandermuller/boost-core/compare/0.1.1...0.1.2) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.1.1...0.1.2

## [0.1.1](https://github.com/sandermuller/boost-core/compare/0.1.0...0.1.1) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.1.0...0.1.1

## [0.1.0](https://github.com/sandermuller/boost-core/compare/...0.1.0) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/commits/0.1.0
