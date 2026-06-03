# Public API

The semver-protected surface of `sandermuller/boost-core`. Everything listed under **Stable surface** is covered by [Semantic Versioning](https://semver.org/spec/v2.0.0.html): it will not break in a MINOR or PATCH of the same MAJOR. Everything else — every class marked `@internal`, and all on-disk regenerable state — may change in any release, including patches.

## Versioning

This package follows Semantic Versioning 2.0.0. Pre-`1.0.0`, MINOR bumps may still break the public API (called out in `CHANGELOG.md` / `UPGRADING.md`). From `1.0.0` on, the surface below is locked for the `1.x` line.

## Stable surface

### Config authoring (`boost.php`)

- `SanderMuller\BoostCore\Config\BoostConfig` — the resolved config + `::configure()` entry point and its read accessors (`hasAgent`, `hasTag`, `isVendorAllowed`, `isEmitterDisabled`, `excludesSkill`, `excludesGuideline`). The positional `__construct` is `@internal` (build via `configure()`).
- `SanderMuller\BoostCore\Config\BoostConfigBuilder` — the fluent builder: `withAgents`, `withAllowedVendors`, `withSkillsPath`, `withGuidelinesPath`, `withCommandsPath`, `withDisabledEmitters`, `withGitignoreManagement`, `withTags`, `withExcludedSkills`, `withExcludedGuidelines`, `withRemoteSkills`, `withSkillRenderers`, `withDisabledRenderers`, `withConventions`. (`build()` is `@internal` — the loader calls it.)
- `SanderMuller\BoostCore\Enums\Agent` — the supported agent cases.
- `SanderMuller\BoostCore\Enums\Tag` — convenience tag cases (non-authoritative; any string is a valid tag). Existing case backing-values are stable; new cases may be added (additive — closes nothing). `withTags()` accepts `Tag|string`.
- `SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource` + `RemoteSkillRef` — the `withRemoteSkills([...])` value objects (`::githubBundle()`, `::githubPath()`).

### Plugin contracts

- `SanderMuller\BoostCore\Contracts\FileEmitter` + `SanderMuller\BoostCore\Sync\SyncContext` (the received context) + `SanderMuller\BoostCore\Sync\EmittedFile` — emit custom files during sync. `emit()` returns `iterable<EmittedFile>` (zero = skip, one, or many). Parameterless constructors only. The context exposes `projectRoot`, the `BoostConfig`, and the installed-package set via `SanderMuller\BoostCore\Sync\InstalledPackages` / `PackageInfo` (also `@api`). `SyncContext` is received, not constructed by emitters.
- `SanderMuller\BoostCore\Contracts\SkillRenderer` + `SanderMuller\BoostCore\Skills\Rendering\RenderContext` (param), plus `PassthroughRenderer`, `InvalidSkillRendererException`, `SkillRenderException`.
- `SanderMuller\BoostCore\Contracts\BoostWrapperContract` + `SanderMuller\BoostCore\Agents\AgentTarget` — wrapper packages compute their emit surface against these. Only `AgentTarget`'s path/identity methods (`agent`, `skillsDirectoryRelative`, `guidelinesFileRelative`, `commandsDirectoryRelative`, `commandFileExtension`, `gitignorePatterns`) are `@api`; its `plan`/`format*`/`transpile*` methods are `@internal` (they operate on internal engine types).

### Composer hooks

- `SanderMuller\BoostCore\Scripts\BoostAutoSync::run` / `runWithSummary` — the `post-install-cmd` / `post-update-cmd` targets.
- `BoostAutoSync::syncUserScope` / `syncUserScopeOnce` — in-process self-sync for globally-installed CLI tools.

New parameters on any stable method are always optional-with-default; their absence-vs-presence is not a breaking change.

### CLI (`bin/boost`)

The command names, their documented options, and the exit-code contract (`0` ok, `1` failure, `2` usage) are stable. Human-readable output text is NOT a contract; `--json` envelopes (where offered) are.

**Ambiguous config fails loud (behavior contract).** When BOTH a root `boost.php` and a `.config/boost.php` are present, config resolution fails loudly (non-zero exit) rather than silently picking one. This fail-on-ambiguous *behavior* is contractual; the thrown exception class itself stays `@internal`.

### Family-CLI extension point

- `SanderMuller\BoostCore\Commands\BoostBaseCommand` — the base class wrapper/tooling packages extend to ship their own `bin/<tool>` commands. The frozen `@api` surface is exactly two protected helpers: `addWorkingDirOption(): static` (registers the `--working-dir`/`-d` option) and `resolveProjectRoot(InputInterface): string` (resolves the project root, `--working-dir` → cwd). Subclass via Symfony's normal `configure()` / `execute()`. The class's other protected members (the `--config` option + config-loading helpers) are `@internal` — a config-loading extension point is a separate, heavier contract deliberately not locked at 1.0.

### Textual / wire formats

These aren't PHP types, but they're authored or observed by consumers/publishers, so they're part of the contract (changing them is a major bump):

- **`boost:conv` inline token** — `<!--boost:conv path="…" mode="…" fallback="…"-->` (and the ` ```boost:conv ` fence). Vendor skill/guideline authors hand-write these; the `\`-escape (`<!--\boost:conv…-->`) is also stable.
  - **Path syntax** (stable): the legacy `$.`-prefix, dotted `group.key` (`jira.project_key`), bare group (`mcp`), and open-vocab sub-key (`group.<sub-key>`, e.g. `mcp.<server>` — the `mcp.` prefix won't be repurposed).
  - **Modes + type×mode validity** (frozen): the modes are `inline`, `bullets`, `yaml`, `json`, valid by slot value type as — scalar → `inline`/`yaml`/`json`; list → `inline`/`bullets`/`yaml`/`json`; map → `yaml`/`json`; with `inline` additionally requiring a single-line scalar OR a scalar list (a multi-line scalar, or a list of structured items, rejects `inline` as a render-class error). (So a list-of-strings → `inline` is stably valid.)
  - **Resolution behavior** (frozen): a token resolves `declared value → schema default → fallback`; an unset slot with a `fallback` inlines the fallback string verbatim (in the token's mode), and an unset slot with no default and no fallback is a render-class error that fails `boost sync --check`. The resolved value FULLY replaces the `<!--boost:conv…-->` comment — zero marker residue in emitted output (a surviving token is a leak, surfaced by `boost doctor` / `validate`).
- **Conventions schema-version handshake** — the negotiation MECHANISM is frozen, not just its current instance. A vendor skill declares `metadata.schema-required` as a caret RANGE (e.g. `"^1"`); the emitted Project Conventions block carries `schema-version: N` as a const INTEGER; the engine ships the skill's slots only when the range ⊇ the const. Additive growth within a major (new optional slots) stays compatible under the same range; only a breaking redesign bumps both the integer and the required range. `^1` / `schema-version: 1` is the current (v1) instance.
- **Tag filtering — two co-equal channels, subset-AND** — a vendor skill/guideline ships to a project only when its declared tags ⊆ the project's `withTags()` set (an untagged skill/guideline ships everywhere; a malformed tag declaration fails CLOSED — ships nowhere). Tags are declared on two stable, **co-equal** channels: a SKILL via `metadata.boost-tags` in its frontmatter; a GUIDELINE via `metadata.boost-tags` frontmatter **or** the sidecar manifest `.boost-tags.yaml`. When a guideline carries frontmatter tags they win; otherwise the sidecar is read — but the sidecar is a **first-class, standalone, non-deprecated** channel (guidelines often must stay frontmatter-free for laravel/boost compatibility, so the sidecar is never relegated to second-class). Sidecar shape (frozen): a YAML map of exact guideline filename → a space-delimited tag string (no list form, no globs). Tag comparison is case/space-normalized (`strtolower(trim(...))`) identically on the declared and the `withTags()` side.
- **Conventions exclude-key grammar** — the `vendor/package:name` form passed to `withExcludedSkills()` / `withExcludedGuidelines()`.
- **Managed `.gitignore` block markers** — `# >>> boost (managed) >>>` … `# <<< boost (managed) <<<` (written into the consumer's tracked `.gitignore`).
- **`Agent` enum backing values** — the kebab strings (`claude-code`, …) double as on-disk directory slugs and cross the wrapper boundary; new agents may be added, existing values won't change.

## Internal (not covered by semver)

- Every class marked `@internal` — the engine: `Sync\`, `Discovery\`, `Conventions\`, `Agents\` (except `AgentTarget`), `Commands\`, the `Skills\` internals, `Env`, and the internal `Config\` loader/writer/printer/path classes. Do not import these.
- On-disk regenerable state: the sync manifest (`.boost/manifest.json` ⁄ `.config/boost/manifest.json`), the remote-skill ledger (`remote-manifest.json`), the user-scope manifests under `~/.boost/manifests/`, the `.boost/` ⁄ `.config/boost/` runtime dir, and the cache sentinel. Their schema is not a contract.

## Deprecation policy

A stable (`@api`) element is deprecated before it is removed: marked `@deprecated` in PHPDoc — and, where it has a runtime code path, emitting `E_USER_DEPRECATED` — in a MINOR release, then removed no earlier than the next MAJOR. Deprecations are listed under `### Deprecated` in `CHANGELOG.md` so they surface in release notes.

## Removed APIs

<!-- Track removed APIs here so consumers know what was removed when. Example:
- `1.0.0` — Removed `OldClass::oldMethod()`. Migrate to `NewClass::newMethod()`.
-->
