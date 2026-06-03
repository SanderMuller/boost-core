# Public API

The semver-protected surface of `sandermuller/boost-core`. Everything listed under **Stable surface** is covered by [Semantic Versioning](https://semver.org/spec/v2.0.0.html): it will not break in a MINOR or PATCH of the same MAJOR. Everything else — every class marked `@internal`, and all on-disk regenerable state — may change in any release, including patches.

## Versioning

This package follows Semantic Versioning 2.0.0. Pre-`1.0.0`, MINOR bumps may still break the public API (called out in `CHANGELOG.md` / `UPGRADING.md`). From `1.0.0` on, the surface below is locked for the `1.x` line.

## Stable surface

### Config authoring (`boost.php`)

- `SanderMuller\BoostCore\Config\BoostConfig` — the resolved config + `::configure()` entry point and its read accessors (`hasAgent`, `hasTag`, `isVendorAllowed`, `isEmitterDisabled`, `excludesSkill`, `excludesGuideline`). The positional `__construct` is `@internal` (build via `configure()`).
- `SanderMuller\BoostCore\Config\BoostConfigBuilder` — the fluent builder: `withAgents`, `withAllowedVendors`, `withSkillsPath`, `withGuidelinesPath`, `withCommandsPath`, `withDisabledEmitters`, `withGitignoreManagement`, `withTags`, `withExcludedSkills`, `withExcludedGuidelines`, `withRemoteSkills`, `withSkillRenderers`, `withDisabledRenderers`, `withConventions`. (`build()` is `@internal` — the loader calls it.)
- `SanderMuller\BoostCore\Enums\Agent` — the supported agent cases.
- `SanderMuller\BoostCore\Enums\Tag` — convenience tag cases (non-authoritative; any string is a valid tag).
- `SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource` + `RemoteSkillRef` — the `withRemoteSkills([...])` value objects (`::githubBundle()`, `::githubPath()`).

### Plugin contracts

- `SanderMuller\BoostCore\Contracts\FileEmitter` + `SanderMuller\BoostCore\Sync\SyncContext` (the received context) + `SanderMuller\BoostCore\Sync\EmittedFile` (return) — emit custom files during sync. Parameterless constructors only. The context exposes `projectRoot`, the `BoostConfig`, and the installed-package set via `SanderMuller\BoostCore\Sync\InstalledPackages` / `PackageInfo` (also `@api`). `SyncContext` is received, not constructed by emitters.
- `SanderMuller\BoostCore\Contracts\SkillRenderer` + `SanderMuller\BoostCore\Skills\Rendering\RenderContext` (param), plus `PassthroughRenderer`, `InvalidSkillRendererException`, `SkillRenderException`.
- `SanderMuller\BoostCore\Contracts\BoostWrapperContract` + `SanderMuller\BoostCore\Agents\AgentTarget` — wrapper packages compute their emit surface against these. Only `AgentTarget`'s path/identity methods (`agent`, `skillsDirectoryRelative`, `guidelinesFileRelative`, `commandsDirectoryRelative`, `commandFileExtension`, `gitignorePatterns`) are `@api`; its `plan`/`format*`/`transpile*` methods are `@internal` (they operate on internal engine types).

### Composer hooks

- `SanderMuller\BoostCore\Scripts\BoostAutoSync::run` / `runWithSummary` — the `post-install-cmd` / `post-update-cmd` targets.
- `BoostAutoSync::syncUserScope` / `syncUserScopeOnce` — in-process self-sync for globally-installed CLI tools.

New parameters on any stable method are always optional-with-default; their absence-vs-presence is not a breaking change.

### CLI (`bin/boost`)

The command names, their documented options, and the exit-code contract (`0` ok, `1` failure, `2` usage) are stable. Human-readable output text is NOT a contract; `--json` envelopes (where offered) are.

## Internal (not covered by semver)

- Every class marked `@internal` — the engine: `Sync\`, `Discovery\`, `Conventions\`, `Agents\` (except `AgentTarget`), `Commands\`, the `Skills\` internals, `Env`, and the internal `Config\` loader/writer/printer/path classes. Do not import these.
- On-disk regenerable state: the sync manifest (`.boost/manifest.json` ⁄ `.config/boost/manifest.json`), the remote-skill ledger (`remote-manifest.json`), the user-scope manifests under `~/.boost/manifests/`, the `.boost/` ⁄ `.config/boost/` runtime dir, and the cache sentinel. Their schema is not a contract.

## Deprecation policy

A stable (`@api`) element is deprecated before it is removed: marked `@deprecated` in PHPDoc — and, where it has a runtime code path, emitting `E_USER_DEPRECATED` — in a MINOR release, then removed no earlier than the next MAJOR. Deprecations are listed under `### Deprecated` in `CHANGELOG.md` so they surface in release notes.

## Removed APIs

<!-- Track removed APIs here so consumers know what was removed when. Example:
- `1.0.0` — Removed `OldClass::oldMethod()`. Migrate to `NewClass::newMethod()`.
-->
