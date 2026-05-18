# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.1.2...HEAD)

### Added

- **`BoostCorePlugin` now auto-syncs user-scope skills in `composer global` context.** When Composer runs under `composer global <cmd>` (detected via `composer->getConfig()->get('home') === cwd` AND `argv` contains `global`), the plugin iterates every globally-installed package, finds those shipping `resources/boost/skills/`, and syncs each into the agents' home-directory skill folders (`~/.claude/skills/{package}/`, etc.) — equivalent to `vendor/bin/boost sync --scope=user` per package. Lets packages like `sandermuller/repo-init` drop their own post-install scripts; installing them via `composer global require` is enough. `BOOST_SKIP_AUTOSYNC=1` bypasses, same as project-scope auto-sync.
- **Basename collision detection in global auto-sync.** User-scope paths are namespaced by the package basename today (`~/.claude/skills/<basename>/`). If two globally-installed packages share a basename (e.g. `vendor-a/dup-tool` + `vendor-b/dup-tool`), only the first one synced wins; subsequent packages are skipped with a warning naming the conflicting basename and the already-claimed owner. Run `composer boost:sync --scope=user --working-dir=<pkg>` manually to sync the loser into its own location.
- **`BOOST_SKIP_GITIGNORE=1` env var bypasses `.gitignore` management** even when `boost.php` enables it via `->withGitignoreManagement(true)`. Symmetric to the existing `BOOST_SKIP_AUTOSYNC=1`. Useful for CI runners and ephemeral Docker installs where mutating the project's `.gitignore` is unwanted.

### Known limitations

- **Stale user-scope skills on `composer global remove`.** Removing a globally-installed package does not clean up its previously emitted `~/.{agent}/skills/<package>/` files. Until manifest-based cleanup ships, delete the directory by hand after removing the package.
- **Basename-only namespacing for user-scope paths.** Two packages sharing a basename collide (see above). Planned fix: switch `SyncEngine::packageSuffix()` to a vendor-namespaced slug (`vendor-name/package` → `vendor-name-package`) at the next minor bump; expect a one-time migration of existing `~/.{agent}/skills/<basename>/` directories.

### Fixed

- **Directory-form source skills (`.ai/skills/<name>/SKILL.md`) now emit as `<name>/SKILL.md` instead of being flattened to `<name>.md`.** The sync used to read both flat (`<name>.md`) and dir-form sources but unconditionally wrote flat — so a source layout `.ai/skills/ai-guidelines/SKILL.md` would land as `.claude/skills/ai-guidelines.md`, losing the directory structure that Claude Code (and similar agents) need when a skill bundles companion assets. `Skill` now carries an `isDirectoryForm` flag derived from the source filename, and `AgentTarget` mirrors that layout in the output. Flat sources are unchanged.
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
