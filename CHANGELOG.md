# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.3.4...HEAD)

### Changed (BREAKING — bump to 0.4.0)

- **User-scope skill paths now namespaced by the full `vendor/package` slug** instead of basename-only. `acme/repo-init` writes to `~/.{agent}/skills/acme-repo-init/...` (was `~/.{agent}/skills/repo-init/...`). Closes the long-standing cross-vendor basename-collision known limitation — two packages from different vendors that happened to share a basename used to fight over the same path and trigger boost-core's warn-and-skip collision detection; now they coexist cleanly with distinct slugs. **Auto-migration** runs on the first `syncUser()` against each installed package post-upgrade: detects `~/.{agent}/skills/<basename>/`, renames to `~/.{agent}/skills/<vendor>-<basename>/`. Idempotent and safe (skipped when new-slug dir already exists). The collision-detection code path stays in place defensively but is unreachable in practice. See [`UPGRADING.md`](UPGRADING.md) for migration details.

### Fixed

- **`boost sync` no longer chokes on dead symlinks under managed agent skill dirs.** Consumer projects migrating between renamed vendor packages (e.g. `sandermuller/package-boost` → `sandermuller/package-boost-php`) ended up with dangling symlinks under `.{agent}/skills/<old-pkg>/` pointing into the now-uninstalled `vendor/<old-pkg>/` tree. Previous sync runs stumbled over them and required a manual `find -L -type l -delete` to recover. `SyncEngine` now walks each managed agent skills dir at sync time and unlinks dead symlinks before writing fresh state — live symlinks (target exists) are left alone and not recursed into. Same `composer install` → `boost sync` flow now self-heals across vendor renames.

### Fixed (historical — already shipped, awaiting CHANGELOG cleanup)

- **`BOOST_SKIP_AUTOSYNC` env var now honored by `Scripts\BoostAutoSync::run` and `::runWithSummary`.** The plugin's `onPostAutoloadDump` hook honored the env var, but the script callbacks shipped in 0.3.1 didn't — so consumers wiring `BoostAutoSync::run` into their own `post-install-cmd` per the documented recommendation lost the escape hatch the env var was supposed to provide. The check now lives in the shared `resolveAndRun` helper so both callables (and any future siblings) inherit it; CI runners + ephemeral Docker installs can disable auto-sync uniformly regardless of which entry point fires.
  
- **`BoostAutoSync` callbacks now resolve `bin/boost` at the project root as a fallback** when `config.bin-dir/boost` isn't present. Composer only symlinks dependency bins into `vendor/bin/`, never the root package's own bins — so boost-core's own dev tree (and any future package that self-references its own bin) couldn't use `BoostAutoSync::run` for its own `post-install-cmd`. The fallback closes that gap while keeping all the existing guards (`--no-dev`, `BOOST_SKIP_AUTOSYNC`) intact — one callable, both contexts.
  

### Internal

- **Self-sync boost-core's own fan-out (item #9).** Composer plugins don't activate in their own dev env, so `BoostCorePlugin::onPostAutoloadDump` never fires for boost-core. 69 fan-out files (per-agent skill symlinks + root guideline files like `AGENTS.md` / `CLAUDE.md` / `GEMINI.md`) were committed to git as a workaround. Now that `BoostAutoSync::run` resolves the root-package bin (see above), `composer.json`'s `post-install-cmd` / `post-update-cmd` use the canonical callable and the 69 files are removed from git. All paths were already gitignored under the managed `# >>> boost (managed) >>>` block; future syncs regenerate them locally without staleness drift.
  
- **AST round-trip stability tests for `BoostConfigWriter` (item #10).** `BoostConfigWriter` has documented best-effort semantics on formatting (header docblocks stripped, blank-line layout drift). The writer's behavioural contract — parse → write → parse → reload returns identical agents / vendors / disabled-emitters lists — wasn't pinned by any test. Six new cases in `tests/Unit/Config/BoostConfigWriterRoundTripTest.php` pin semantic equivalence (not formatting), including a double-round-trip idempotency case and a case exercising the exact starter-template shape `InstallCommand` emits on first run. Lets a future switch to PHP-Parser's `printFormatPreserving` printer be verified against the same contract without rewriting assertions.
  

### Added

- **`SanderMuller\BoostCore\Scripts\BoostAutoSync::runWithSummary` script callback** for user-invoked composer scripts (e.g. `composer sync-ai`) where silence on success reads as a no-op. Streams the binary's one-line success summary (`[OK] Sync done. wrote=X, unchanged=Y`) through Composer's IO. The existing `BoostAutoSync::run` callback stays silent on success — designed for the auto-firing `post-install-cmd` / `post-update-cmd` contexts where any per-install output is noise. Two callables, each self-documenting via name, no shared env-var knob.
  
- **`SanderMuller\BoostCore\Scripts\BoostAutoSync::run` script callback** for use in consumer packages' `post-install-cmd` / `post-update-cmd` hooks. Replaces the bash one-liner pattern (`if [ "$COMPOSER_DEV_MODE" = "1" ]; then vendor/bin/boost sync 2>/dev/null || true; fi`), which breaks on Windows cmd.exe. The PHP callback uses `Event::isDevMode()` (proper API instead of `$COMPOSER_DEV_MODE`), honors `composer config.bin-dir` overrides, and surfaces non-zero exits through Composer's IO instead of swallowing them. Add as a direct dep: `symfony/process: ^7.0`.
  

### Fixed

- **User-scope sync no longer double-nests when the skill dir name matches the package basename.** Single-skill tooling packages (e.g. `sandermuller/repo-init` shipping `resources/boost/skills/repo-init/SKILL.md`) landed at `~/.{agent}/skills/repo-init/repo-init/SKILL.md` — the package suffix and the skill dir were both injected even when they were the same name. `SyncEngine::rewriteForUserScope` now strips the redundant first component when it equals the package suffix; output is `~/.{agent}/skills/repo-init/SKILL.md`. Multi-skill packages and packages whose skill name differs from the package basename are unaffected (the package/skill split still distinguishes them in the path).

> **Note:** the section below was queued as Unreleased before 0.3.0 was cut and the contents shipped as part of that tag. Leaving it visible until the CI `update-changelog` workflow's "reset Unreleased on tag" behaviour catches up; treat the entries between this note and `## [0.3.0]` as historical.

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

## [0.3.4](https://github.com/sandermuller/boost-core/compare/0.3.3...0.3.4) - 2026-05-18

### Fixed

- **`boost sync` no longer chokes on dead symlinks under managed agent skills dirs.** Consumer projects migrating between renamed vendor packages (e.g. the long-running `sandermuller/package-boost` → `sandermuller/package-boost-php` + `sandermuller/package-boost-laravel` split) ended up with dangling symlinks under `.{agent}/skills/<old-pkg>/` pointing into the now-uninstalled `vendor/<old-pkg>/` tree. Previous sync runs stumbled over them and required a manual `find -L -type l -delete` across all 6 agent dirs to recover. `SyncEngine` now walks each managed agent skills dir at sync time and unlinks dead symlinks before writing fresh state — live symlinks (target exists) are left alone and not recursed into, so legitimate user-placed links survive untouched. Same `composer install` → `boost sync` flow now self-heals across vendor renames.

### Upgrade notes

`composer require sandermuller/boost-core:^0.3.4` — drop-in, no migration needed. If you're sitting on a project that previously had dead symlinks and were running the manual `find -L` workaround, the next sync after this bump self-cleans.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.3.3...0.3.4

## [0.3.3](https://github.com/sandermuller/boost-core/compare/0.3.2...0.3.3) - 2026-05-18

### Fixed

- **`BoostAutoSync` callbacks now resolve `bin/boost` at the project root as a fallback** when `config.bin-dir/boost` isn't present. Composer only symlinks dependency bins into `vendor/bin/`, never the root package's own bins — so boost-core's own dev tree (and any future package that self-references its own bin) couldn't use `BoostAutoSync::run` for its own `post-install-cmd` without bypassing the documented guards. The fallback closes that gap; both `--no-dev` and `BOOST_SKIP_AUTOSYNC=1` are now honored uniformly whether the callable runs in a consumer project or a self-referencing root package.

### Internal

- **Self-sync boost-core's own fan-out.** Composer plugins don't activate in their own dev env, so `BoostCorePlugin::onPostAutoloadDump` never fires for boost-core itself. The workaround was committing 69 per-agent fan-out files to git, which drifted from `.ai/` sources on every skill change. Now that `BoostAutoSync::run` handles the root-package case (see Fixed above), `composer.json`'s `post-install-cmd` / `post-update-cmd` use the canonical callable and the 69 files are removed from git. All paths were already gitignored under the managed `# >>> boost (managed) >>>` block; future syncs regenerate them locally.
  
- **AST round-trip stability tests for `BoostConfigWriter`.** `BoostConfigWriter` has documented best-effort semantics on formatting (header docblocks stripped, blank-line layout may drift). Six new test cases in `tests/Unit/Config/BoostConfigWriterRoundTripTest.php` pin semantic equivalence across `parse → write → parse → reload` cycles — agents, vendors, and disabled-emitters lists must survive identically regardless of formatting drift. Includes a double-round-trip idempotency case and a case exercising the exact starter-template shape `InstallCommand` emits on first run. Lets a future switch to PHP-Parser's `printFormatPreserving` printer be verified against the same contract without rewriting assertions.
  

### Upgrade notes

`composer require sandermuller/boost-core:^0.3.3` — drop-in, no migration needed. The fallback fix only changes behaviour for packages that wire `BoostAutoSync::run` into their own `composer.json` while also being self-referencing (their own bin lives at the project root, not `vendor/bin/`). Consumer projects depending on boost-core via `vendor/` are unaffected.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.3.2...0.3.3

## [0.3.2](https://github.com/sandermuller/boost-core/compare/0.3.1...0.3.2) - 2026-05-18

### Added

- **`SanderMuller\BoostCore\Scripts\BoostAutoSync::runWithSummary` script callback** for user-invoked Composer scripts (e.g. `composer sync-ai`) where silence on success reads as a no-op. Streams the binary's one-line success summary (`[OK] Sync done. wrote=X, unchanged=Y`) through Composer's IO. The existing `BoostAutoSync::run` callback (silent on success — designed for the auto-firing `post-install-cmd` / `post-update-cmd` contexts) is unchanged. Two callables, each self-documenting via name:
  
    ```json
    "scripts": {
  "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
  "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
  "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
  }
  
  
  
    ```

### Fixed

- **`BOOST_SKIP_AUTOSYNC=1` is now honored by both `Scripts\BoostAutoSync::run` and `::runWithSummary`.** The plugin's `onPostAutoloadDump` hook honored the env var, but the 0.3.1 script callbacks didn't — so consumers wiring `BoostAutoSync::run` into their own `post-install-cmd` per the documented recommendation lost the escape hatch every README in the family advertised as working. The check now lives in a shared helper inside `BoostAutoSync` so both callables (and any future siblings) inherit it; CI runners + ephemeral Docker installs can disable auto-sync uniformly regardless of which entry point fires.

### Internal

- Branch-alias bumped `dev-main: 0.x-dev` → `dev-main: 0.3.x-dev` to match the actual tagged surface. Consumers should pin `sandermuller/boost-core: ^0.3` (NOT `^1.0@dev`).
- `RELEASING.md` gains a "Version-stream policy" section documenting the 0.3.x → 0.4.0 (manifest cleanup + vendor-namespaced slugs) → 1.0.0 (FileEmitter stable) roadmap, plus a "Constraint floors must match feature usage" rule for downstream consumers who reference mid-minor features (e.g. `BoostAutoSync::run` needs `^0.3.1` floor; `::runWithSummary` needs `^0.3.2`).
- Internal Rector + Pint sweep across the 0.3.1 → 0.3.2 surface. No behaviour change.

### Upgrade notes

`composer require sandermuller/boost-core:^0.3.2` to use `runWithSummary`. If you only need `run` and don't care about the env-var fix, `^0.3.1` is still fine.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.3.1...0.3.2

## [0.3.1](https://github.com/sandermuller/boost-core/compare/0.3.0...0.3.1) - 2026-05-18

### Added

- **`SanderMuller\BoostCore\Scripts\BoostAutoSync::run` Composer script callback.** Cross-platform replacement for the bash one-liner pattern (`if [ "$COMPOSER_DEV_MODE" = "1" ]; then vendor/bin/boost sync 2>/dev/null || true; fi`) that consumer packages were using in their own `post-install-cmd` hooks — that bash form breaks on Windows cmd.exe. The PHP callback uses `Event::isDevMode()` (proper API instead of the `$COMPOSER_DEV_MODE` env var), honors `composer config.bin-dir` overrides, and surfaces non-zero exits through Composer's IO instead of swallowing them. Wire it into your consumer package's `composer.json`:
  
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
  End-user installs already get auto-sync via the plugin's `onPostAutoloadDump` hook — this callback is specifically for plugin packages in the `boost-*` family that need their own explicit script entry. Adds `symfony/process: ^7.0` as a direct require (it was transitively present already; the new public API makes the dep explicit).
  

### Fixed

- **User-scope sync no longer double-nests when the skill directory name matches the package basename.** Single-skill tooling packages (e.g. a `vendor/repo-init` shipping `resources/boost/skills/repo-init/SKILL.md`) used to land at `~/.{agent}/skills/repo-init/repo-init/SKILL.md` — the package suffix and the skill dir were both injected even when they were the same name. `SyncEngine::rewriteForUserScope` now strips the redundant first component when it equals the package suffix; output is the expected `~/.{agent}/skills/repo-init/SKILL.md`. Multi-skill packages and packages whose skill name differs from the package basename are unaffected.

### Docs

- README adds an "Composer script callback (for plugin-package authors)" subsection documenting the new `BoostAutoSync` callable.
- README adds an Upgrading section linking [`UPGRADING.md`](https://github.com/sandermuller/boost-core/blob/main/UPGRADING.md); the `FileEmitter @experimental` warning moved into a GitHub `[!NOTE]` admonition under Upgrading.

### Upgrade notes

`composer require sandermuller/boost-core:^0.3.1` (or stay on `^0.3.0` — both are non-breaking). Bundle packages (`sandermuller/project-boost`, `sandermuller/package-boost-php`, `sandermuller/package-boost-laravel`) will roll the new boost-core through transitively as they re-tag.

The `BoostAutoSync::run` script callback is opt-in. Consumer packages can adopt it on their own schedule; existing bash hooks continue to work on Unix shells.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.3.0...0.3.1

## [0.3.0](https://github.com/sandermuller/boost-core/compare/0.2.0...0.3.0) - 2026-05-18

Breaking minor: `composer boost:init` is gone — `composer boost:install` now generates the starter `boost.php` on first run and goes straight into the interactive picker. New users go from 3 commands to 2.

See [`UPGRADING.md`](https://github.com/sandermuller/boost-core/blob/main/UPGRADING.md) for the migration.

### Breaking changes

- **`composer boost:init` removed.** The pre-0.3 flow was `boost:init` → `boost:install` → `boost:sync` (three commands for the first-time setup). `boost:install` now detects a missing `boost.php`, writes the starter inline, and continues into the interactive picker — same final state, one less command. Existing `boost.php` files are loaded unchanged; no overwrite, no re-init prompt.
  - **Migration:** swap any `composer boost:init` invocation in CI scripts / docs / hooks for `composer boost:install`. To force a fresh starter, delete `boost.php` by hand first.
  - The `BoostConfigNotFoundException` error message now points users at `composer boost:install` instead of `composer boost:init`. Any error-handling code that grepped the old text should be updated.
  

### Internal

- **Branch alias realigned.** `extra.branch-alias.dev-main` was `1.x-dev` (leftover from earlier sibling-repo intent that never matched the actual tagged surface). Now `0.x-dev`. Consumers should pin `sandermuller/boost-core: ^0.2.0` (stable) or `^0.3.0@dev` (development); the previous `^1.0@dev` constraint won't resolve to the latest dev-main any more.
- **`SkillLoader` Blade-template skip is now explicit + tested.** The loader was already skipping `*.blade.php` via Finder's `*.md` name filter, but the behaviour wasn't documented and had no fixture coverage. Formalized with a docblock + dedicated test case so vendor packages relying on Blade-rendered skills know boost-core won't fan them out.
- README gains badges + Testing / Changelog / Contributing / Security / Credits sections. New `CONTRIBUTING.md` documents the dev loop + quality gates. New `SECURITY.md` documents the disclosure email (`github@scode.nl`) and supported-versions table.
- Rector + Pint pre-release sweep across 11 files (`NewlineAfterStatementRector`). No behaviour change.

### Upgrade notes

`composer require sandermuller/boost-core:^0.3.0` (or stay on `^0.2.0` if you're not ready for the `boost:init` removal). Bundle packages (`sandermuller/project-boost`, `sandermuller/package-boost-php`, `sandermuller/package-boost-laravel`) will roll the new boost-core through transitively as they re-tag.

For anything using `composer boost:init` today: replace with `composer boost:install` and you're done. No `boost.php` data migration is needed; the file's shape is unchanged.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.2.0...0.3.0

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
