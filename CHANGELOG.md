# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.2.0...HEAD)

### Removed (BREAKING — bump to 0.3.0)

- **`composer boost:init` removed.** The command was a one-shot "write a starter `boost.php` and stop", expected to be followed by `composer boost:install` for the interactive picker. The two-step was friction with no upside — `boost:install` now detects a missing `boost.php` and generates the starter inline before opening the picker. Net usage drops from 3 commands (`init` + `install` + `sync`) to 2 (`install` + `sync`).
  - **Migration:** anything (CI scripts, docs, hooks) calling `composer boost:init` should call `composer boost:install` instead. The starter generation happens automatically on first run and is a no-op on subsequent runs (existing `boost.php` is loaded, not overwritten).
  - See [`UPGRADING.md`](UPGRADING.md) for the 0.2 → 0.3 migration steps.

### Added

- **`BoostCorePlugin` now auto-syncs user-scope skills in `composer global` context.** When Composer runs under `composer global <cmd>` (detected via `composer->getConfig()->get('home') === cwd` AND `argv` contains `global`), the plugin iterates every globally-installed package, finds those shipping `resources/boost/skills/`, and syncs each into the agents' home-directory skill folders (`~/.claude/skills/{package}/`, etc.) — equivalent to `vendor/bin/boost sync --scope=user` per package. Lets packages like `sandermuller/repo-init` drop their own post-install scripts; installing them via `composer global require` is enough. `BOOST_SKIP_AUTOSYNC=1` bypasses, same as project-scope auto-sync.
- **Basename collision detection in global auto-sync.** User-scope paths are namespaced by the package basename today (`~/.claude/skills/<basename>/`). If two globally-installed packages share a basename (e.g. `vendor-a/dup-tool` + `vendor-b/dup-tool`), only the first one synced wins; subsequent packages are skipped with a warning naming the conflicting basename and the already-claimed owner. Run `composer boost:sync --scope=user --working-dir=<pkg>` manually to sync the loser into its own location.
- **`BOOST_SKIP_GITIGNORE=1` env var bypasses `.gitignore` management** even when `boost.php` enables it via `->withGitignoreManagement(true)`. Symmetric to the existing `BOOST_SKIP_AUTOSYNC=1`. Useful for CI runners and ephemeral Docker installs where mutating the project's `.gitignore` is unwanted.

### Known limitations

- **Stale user-scope skills on `composer global remove`.** Removing a globally-installed package does not clean up its previously emitted `~/.{agent}/skills/<package>/` files. Until manifest-based cleanup ships, delete the directory by hand after removing the package.
- **Basename-only namespacing for user-scope paths.** Two packages sharing a basename collide (see above). Planned fix: switch `SyncEngine::packageSuffix()` to a vendor-namespaced slug (`vendor-name/package` → `vendor-name-package`) at the next minor bump; expect a one-time migration of existing `~/.{agent}/skills/<basename>/` directories.

### Fixed

- **`composer boost:*` commands (init/install/sync/scan/doctor/new) work again.** 0.1.2 made `CommandRegistry` return plain Symfony commands, which the standalone `bin/boost` accepts but Composer's plugin `CommandProvider` capability rejects at runtime — every `composer boost:*` invocation failed with `Plugin capability ... returned an invalid value, we expected an array of Composer\Command\BaseCommand objects`. The fix introduces a tiny `BaseCommandAdapter` that wraps each Symfony command as a `Composer\Command\BaseCommand`; the standalone bin path is unchanged and still consumes plain Symfony commands. New `PluginCommandSurfaceTest` runs a real `composer install` + `composer list` + `composer boost:init --help` in a tmp fixture project as a regression guard.

### Changed

- **All skill outputs are now `<name>/SKILL.md`, regardless of source layout.** Earlier 0.2-dev builds mirrored the source layout (flat in, flat out; dir in, dir out), but Claude Code's skill discovery only auto-loads `<name>/SKILL.md` — flat `<name>.md` outputs were silently ignored, leaving flat-sourced host skills undiscoverable. Both flat (`.ai/skills/foo.md`) and dir-form (`.ai/skills/foo/SKILL.md`) sources now emit as `.{agent}/skills/foo/SKILL.md`. **Upgraders:** the sync prunes any obsolete sibling `<name>.md` when it writes the new `<name>/SKILL.md` for the same skill, so you don't need to manually clean up after the bump.
- **Standalone `bin/boost` no longer fatals in end-user (non-dev) installs.** `BoostCoreCommandProvider` implements `Composer\Plugin\Capability\CommandProvider` and `BoostBaseCommand` extended `Composer\Command\BaseCommand` — both classes only exist in vendor/ when `composer/composer` is dev-installed. End users running `vendor/bin/boost` got `Interface "Composer\Plugin\Capability\CommandProvider" not found`. Standalone bin now consumes a new `CommandRegistry` (zero Composer deps) and `BoostBaseCommand` extends `Symfony\Console\Command\Command` directly. Composer plugin path is unchanged.

### Added

- Initial scaffolding via `sandermuller/repo-init` (php-package category, customized to `composer-plugin` type).
- Composer plugin entry (`BoostCorePlugin`) registering a `CommandProvider` for future `boost:*` commands.
- Standalone `bin/boost` entry script for direct invocation outside Composer.
- `FileEmitter` plugin contract sketch (`@experimental`) — see `internal/boost-file-emitter-contract.md` in the design repo.

> **Note:** `0.1.0` and `0.1.1` ship a broken standalone `bin/boost` for end-user installs (fatal at startup, as above). Use `0.1.2`+ if you invoke `vendor/bin/boost` directly. The `composer boost:*` plugin path is unaffected.

## [0.2.0](https://github.com/sandermuller/boost-core/compare/0.1.2...0.2.0) - 2026-05-18

### Highlights

- **Skill output is always `<name>/SKILL.md`.** Both flat (`.ai/skills/foo.md`) and directory-form (`.ai/skills/foo/SKILL.md`) sources now emit as `.{agent}/skills/foo/SKILL.md`. The previous flat output was silently ignored by Claude Code's skill discovery, leaving flat-sourced host skills undiscoverable. Upgraders: the sync prunes the obsolete sibling `<name>.md` automatically when it writes the new `<name>/SKILL.md` for the same skill — no manual cleanup needed.
- **`composer global require <skill-bearing-package>` auto-syncs to `~/.{agent}/skills/<package>/...`.** Detected via `composer->getConfig()->get('home') === cwd` AND `argv` contains `global`. Lets globally-installed tooling packages ship skills without a post-install script. `BOOST_SKIP_AUTOSYNC=1` bypasses.
- **Basename collision detection in global auto-sync.** Two globally-installed packages sharing a basename (`vendor-a/foo` + `vendor-b/foo`) both target `~/.{agent}/skills/foo/`. The first one syncs and the second is skipped with a warning naming the conflict. Run `composer boost:sync --scope=user --working-dir=<pkg>` manually for the loser.
- **New `BOOST_SKIP_GITIGNORE=1` env var** bypasses `.gitignore` management even when `boost.php` enables it via `->withGitignoreManagement(true)`. Symmetric to the existing `BOOST_SKIP_AUTOSYNC=1`. Useful for CI runners and ephemeral Docker installs.

### Fixed

- **`composer boost:*` commands (init/install/sync/scan/doctor/new) work again.** 0.1.2 shipped `CommandRegistry` returning plain Symfony commands; the standalone `bin/boost` accepts them but Composer's plugin `CommandProvider` capability rejects them with `Plugin capability ... returned an invalid value, we expected an array of Composer\Command\BaseCommand objects`. Fixed by a thin `BaseCommandAdapter` wrapping each Symfony command as a Composer `BaseCommand`. The adapter dispatches via `ReflectionMethod::invoke($inner, 'execute')` so Composer-global flags like `--no-interaction` survive the wrapper.
- **Standalone `bin/boost` no longer fatals in end-user (non-dev) installs.** 0.1.0/0.1.1 transitively loaded `Composer\Plugin\Capability\CommandProvider` from the standalone path, which doesn't exist when `composer/composer` is dev-only (i.e. the common case for end users). `bin/boost` now consumes a Composer-free `CommandRegistry`; `BoostBaseCommand` extends `Symfony\Console\Command\Command` directly.

### Known limitations

- **Stale user-scope skills on `composer global remove`.** Removing a globally-installed package does not clean up its previously emitted `~/.{agent}/skills/<package>/` files. Until manifest-based cleanup ships, delete the directory by hand after removing the package.
- **Basename-only namespacing for user-scope paths.** Two packages sharing a basename collide (see above). Planned fix: switch `SyncEngine::packageSuffix()` to a vendor-namespaced slug (`vendor-name/package` → `vendor-name-package`) at the next minor bump; expect a one-time migration of existing `~/.{agent}/skills/<basename>/` directories.

### Upgrade notes

Drop-in for projects that consume `boost-core` via a bundle package — re-run `composer install` to trigger the auto-sync, then commit the regenerated `.{agent}/skills/<name>/SKILL.md` if you previously had flat outputs tracked in git. Any pre-0.2 `.{agent}/skills/<name>.md` siblings are deleted on first sync after the bump.

For tooling authors who publish their own skills: the user-scope output path changed from `~/.{agent}/skills/<package>/<skill>.md` to `~/.{agent}/skills/<package>/<skill>/SKILL.md`. Update any documentation or post-install scripts that reference the old shape.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.1.2...0.2.0

## [0.1.2](https://github.com/sandermuller/boost-core/compare/0.1.1...0.1.2) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.1.1...0.1.2

## [0.1.1](https://github.com/sandermuller/boost-core/compare/0.1.0...0.1.1) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.1.0...0.1.1

## [0.1.0](https://github.com/sandermuller/boost-core/compare/...0.1.0) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/commits/0.1.0
