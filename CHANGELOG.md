# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.21.0...HEAD)

## [0.21.0](https://github.com/sandermuller/boost-core/compare/0.20.0...0.21.0) - 2026-06-03

<!-- verified-sha: fecb60c3a33c064c121e321c1bb299a00fff6f1d -->
### Breaking

- **`FileEmitter::emit()` now returns `iterable<EmittedFile>`** (was `?EmittedFile`). An emitter can emit zero (an empty iterable to skip), one, or many files in a single sync — each is validated, written, and reported independently. Update your emitter's return type and wrap a single file in an array:
  
  ```php
  // before
  public function emit(SyncContext $ctx): ?EmittedFile
  {
      return new EmittedFile(relativePath: '.mcp.json', content: $json);
  }
  // after
  public function emit(SyncContext $ctx): iterable
  {
      return [new EmittedFile(relativePath: '.mcp.json', content: $json)];
  }
  
  ```
  Returning `null` no longer compiles; return `[]` to skip. See [`UPGRADING.md`](../UPGRADING.md). This is the only `FileEmitter` shape change planned before `1.0` — the signature locks at the `1.0` tag.
  

### Added

- **Multi-file emitters.** A single emitter can produce a whole set of files (e.g. an `.mcp.json` plus a sidecar). Orphan reaping is per-FQCN aware: when an emitter stops producing a file it once owned, that dormant file is reaped on the next sync — unless the emitter is fully down this run, in which case its prior files are preserved (never lossy).

### Fixed

- **A generator `emit()` that throws mid-iteration no longer aborts the sync.** If an emitter yields some files and then throws, it is recorded `errored` and the sync continues with the remaining emitters. Emit is **all-or-nothing on failure**: a crashed emitter never half-applies — files it yielded before the throw are not written. (Return an array rather than a throwing generator if you want already-computed files to land regardless of a later problem.)

### Internal

- Branch alias advanced to `0.21.x-dev`.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.20.0...0.21.0

## [0.20.0](https://github.com/sandermuller/boost-core/compare/0.19.0...0.20.0) - 2026-06-03

<!-- verified-sha: d2abb943be245c7f6dc4c76eaf5fac80188dc64b -->
### 0.20.0

The 1.0-readiness release: boost-core now **declares and locks its public API surface**, so the next step to a `1.0` tag is a decision, not more work. One small breaking change in the config API, and a clear line drawn between what semver covers and what's internal.

#### Breaking

- **`withTags()` now takes an array.** It was the only `boost.php` collection setter that was variadic; it now matches every other one (`withAgents`, `withRemoteSkills`, …):
  
  ```php
  // before
  ->withTags(Tag::Php, Tag::Jira)
  // after
  ->withTags([Tag::Php, Tag::Jira])
  
  
  ```
  `boost install`'s tag picker writes the new array form, and `boost sync` still parses + migrates an existing variadic `withTags(...)` in your `boost.php` — but update the call by hand to avoid a `TypeError` when the config is next loaded directly. See [`UPGRADING.md`](../UPGRADING.md).
  

#### Added

- **A declared, enforced public API.** Every class is now marked `@api` or `@internal`, [`PUBLIC_API.md`](../PUBLIC_API.md) enumerates the committed surface, and the README has a new **Versioning & stability** section. The promise covers: the `boost.php` authoring API (`BoostConfig`, the builder, `Agent`/`Tag`, `RemoteSkillSource`), the CLI (command names, options, exit codes), the `BoostAutoSync` composer hooks, and the plugin contracts. Everything else — the whole sync engine — is `@internal` and excluded. An architecture test fails the build if a new engine class isn't marked, so the boundary can't erode.
- **The `FileEmitter` and `SkillRenderer` plugin contracts are locked stable** (`@api`), no longer experimental — including the `SyncContext` / `EmittedFile` / `RenderContext` value objects. Parameterless constructors only; their method signatures won't change within `1.x`.
- **`--config` works on every command.** `slots`, `tags`, and `paths` now accept `--config <path>` like the rest, so a `.config/boost.php`-layout project can point any command at its config.
- **A deprecation policy.** Stable elements are deprecated (with `@deprecated` + a runtime notice) in a minor and removed no earlier than the next major — documented in `PUBLIC_API.md`.
- **The public surface is self-contained.** No `@api` method or property exposes an internal engine type — an architecture test fails the build if one ever does. `AgentTarget`'s `@api` surface is narrowed to the path/identity methods wrapper packages use; its planning/formatting methods are internal. The `FileEmitter` context exposes the installed-package set via `InstalledPackages` / `PackageInfo`, both now part of the stable contract.

#### Changed

- **`boost --version` reports the real installed version** instead of a hardcoded placeholder.
- **`boost doctor` is documented as advisory-only** — it exits `0` even when it surfaces drift, leaked tokens, or shadows (non-zero only on a config-load failure). Gate CI on `boost sync --check` or `boost validate --strict`, which do fail on findings.
- **The legacy `convert-conventions` command is hidden** from the command list (still runnable for stragglers mid-migration; no longer part of the committed CLI contract).
- **`boost install` and `boost scan` fail fast with guidance under `--no-interaction`** / no TTY instead of hanging on the interactive picker.

#### Internal

- The engine is fully `@internal`-annotated behind a pest architecture guard; `BoostConfig`'s positional constructor is internal (build via `BoostConfig::configure()`).
- boost-core now dogfoods the `.config/boost.php` layout for its own configuration.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.19.0...0.20.0

## [0.19.0](https://github.com/sandermuller/boost-core/compare/0.18.3...0.19.0) - 2026-06-02

<!-- verified-sha: 1e85ccbb1e87636d2d951894c803899dea184bc5 -->
User-scope sync gains the cleanup-on-remove story project-scope has had since 0.14.0, and the remote-skill orphan ledger moves out of the repo root to follow the `.config/` layout.

### Added

- **User-scope cleanup-on-remove (`boost sync --scope=user`).** Globally-installed packages that ship skills now get the same lifecycle reaping project-scope already has. boost-core records a per-package ownership manifest at `~/.boost/manifests/<vendor>__<package>.json` (each emitted path → its sha, plus the package's install path), and uses it to clean up on the next sync:
  - **Dropped/renamed skills** — when a package stops shipping a skill, its `~/.{agent}/skills/<vendor>__<package>/<skill>/` copy is removed. A still-shipped skill is kept on *every* agent (so syncing with a narrowed agent set never deletes a live copy for an agent it doesn't drive), while a dropped skill is reaped under *every* agent.
  - **Removed packages (`--scope=user --all`)** — a `composer global remove`d package's user-scope copies are reaped and its manifest deleted. "Removed" is decided by the recorded install path being gone on disk, never by mere absence from the discovered set, so running `--all` from a project-local context can't mass-delete a still-installed package's files. A package that was updated to drop **all** its skills, or replaced in place by a different package at the same path, is reconciled too.
  - **Safety contract** — deletes require a clean run *and* a sha match, so an operator-edited file (its sha diverged) is preserved, a symlinked target is never claimed or unlinked, and a failed delete retains ownership so the next sync retries. `boost sync --scope=user --check` reports a pending reap as drift (with a write/reap breakdown) and changes nothing on disk.
  

### Changed

- **The remote-skill orphan ledger moved into the manifest directory.** For projects using `withRemoteSkills(...)`, the ledger that tracks remote-managed skills is now `.boost/remote-manifest.json` (or `.config/boost/remote-manifest.json` under the `.config/boost.php` layout) instead of `.boost-remote-manifest.json` at the repo root. It now follows the active config layout like the sync manifest, and no longer litters the project root. The move is automatic: a pre-0.19 root-level ledger — and a ledger left in the other layout after moving `boost.php` between root and `.config/` — is migrated on the next sync, with the stale copy removed only after the new one is written (so a transient write failure never leaves you with no ledger). No action required; the ledger is gitignored, regenerable engine state.

### Internal

- Extracted `UserScopeManifest`, `UserScopeReaper`, and `UserScopeManifestWriter` from the sync engine so the new cleanup logic lives in focused, independently-tested units and the engine's complexity budget holds.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.18.3...0.19.0

## [0.18.3](https://github.com/sandermuller/boost-core/compare/0.18.2...0.18.3) - 2026-06-02

<!-- verified-sha: 53bea13f907d1bba8c0c011ae76a222030bac2c4 -->
A bug-fix patch for `withRemoteSkills(...)` consumers.

### Fixed

- **The remote-skill orphan manifest is no longer reaped on every sync.** A project using `withRemoteSkills(...)` saw a spurious `Deleted 1 file(s) … - /.boost-remote-manifest.json` warning on every sync after the first, and remote orphan-pruning was effectively dead — each sync deleted the manifest, so the next sync read an empty one and skipped pruning. Root cause: the managed-file enumerator skipped the sync manifest (`.boost/`) but not the remote-skill manifest, which is likewise written outside the write pipeline; it was classified as a stale managed file and reaped. `StaleFileCleaner::enumerateManagedFiles` now skips `.boost-remote-manifest.json` too. Removing `withRemoteSkills` entirely still prunes the orphaned skill directories (reported as drift) and cleans the manifest — verified by regression tests in both directions.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.18.2...0.18.3

## [0.18.2](https://github.com/sandermuller/boost-core/compare/0.18.1...0.18.2) - 2026-06-02

<!-- verified-sha: 5d1a947dbae0494e07c6c30fa6747c05d2b68a85 -->
A maintenance + documentation patch. No behavior change — internal refactor plus README coverage of the 0.18.0 keep-reason observability.

### Internal

- **SyncEngine decomposition.** Two cohesive, self-contained clusters moved out of the ~2,400-line engine into stateless collaborators, lowering its cognitive-complexity baseline from 263 to 182 (~31%):
  
  - `SyncManifestWriter` — the post-sync ownership-manifest write and the root↔`.config/` stale-layout reconcile (including the `--check` advisory). The engine hands it the enumerated on-disk managed files, so the collaborator never calls back into the engine.
  - `StaleFileCleaner` — the retired-paths registry sweep, the clean-slate prune of managed files no longer emitted, the shared managed-file enumerator, and the recursive delete. Params-only; the retired-paths registry is passed in rather than back-referenced.
  
  Method bodies are verbatim and the full test suite passes unchanged (no test edits), confirming the extraction is behavior-preserving.
  

### Documentation

- The README now documents the keep-reason observability shipped in 0.18.0 (#87): when the drop gate KEEPS the `## Project Conventions` block, `boost sync` (advisory INFO), `boost doctor` (a "Project Conventions block" section), and `boost where --conventions` (a `kept` / `dropped` / `not applicable` / `status unavailable` block-status line) name the artifact and cause holding it open. The `boost doctor` and `boost where --conventions` CLI-reference rows are updated for the keep-reason and runtime-manifest-location surfaces.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.18.1...0.18.2

## [0.18.1](https://github.com/sandermuller/boost-core/compare/0.18.0...0.18.1) - 2026-06-02

<!-- verified-sha: be5d2e3570bea409cfb00ba5380b6e5f1078efea -->
A small follow-through patch that rounds out the `.config/boost/` runtime directory shipped in 0.18.0 — documenting it, surfacing it, and making `boost sync --check` honest about its one-time migration cleanup. No behavior change for root-layout projects.

### Added

- **`boost doctor` shows the active runtime-manifest location.** Doctor now prints whether the gitignored sync manifest lives at root `.boost/` or `.config/boost/` (following the config layout), so operators on the `.config/boost.php` layout can confirm placement at a glance. Shown only when manifest handling is active — with gitignore management disabled (`withGitignoreManagement(false)` / `BOOST_SKIP_GITIGNORE`) boost-core never reads or writes a manifest, so the line is suppressed rather than naming a path that won't be used.
  
- **`boost sync --check` reports a stale old-layout manifest a real sync would prune.** After moving the config between root and `.config/`, a real sync prunes the now-stale manifest left at the old location; `--check` now surfaces that pending one-time cleanup as an advisory (captured before the prune runs, so check and a real sync report it identically). Advisory only — it never registers as drift or fails `--check`: the manifest is gitignored, regenerable, engine-internal state that boost-core deliberately excludes from drift accounting, and a real sync's deletion of it is invisible to `git status`, so flagging drift would be a false-positive for benign git-invisible housekeeping. Closes the known-limitation noted in the 0.18.0 release.
  

### Documentation

- The README ownership-manifest section now documents that the manifest follows the config layout to `.config/boost/manifest.json` under the `.config/boost.php` layout, that a root ↔ `.config/` move carries ownership forward and prunes the stale copy, and that `.config/boost/` is a reserved emitter path.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.18.0...0.18.1

## [0.18.0](https://github.com/sandermuller/boost-core/compare/0.17.0...0.18.0) - 2026-06-02

<!-- verified-sha: 8b04ab853f0b165625a6a47b729b61fced5ed05e -->
An observability + config-layout release. Two classes of silent capability loss now surface instead of disappearing, the kept `## Project Conventions` block now explains itself, and projects on the `.config/` layout get their runtime state grouped under `.config/boost/`. All additive and backward-safe — no behavior changes for projects that don't hit these cases.

### Added

- **Warn on unrenderable skill/guideline sources instead of dropping them silently (#85).** A source whose extension has no registered renderer — e.g. a `SKILL.blade.php` with no `BladeRenderer` declared — used to vanish from the sync with no signal. `boost sync` now emits an advisory warning naming the file, the unclaimed extension, and the fix (register a `SkillRenderer`, or rename to `SKILL.md`); `boost doctor` reports the same across host **and** allowlisted-vendor sources. A shared `UnrenderableSourceScanner` is the single source of truth — skills are scoped to `SKILL.*`, guidelines warn on any file whose extension no renderer claims that isn't a recognized binary/data asset (images, archives, JSON/YAML, etc.). Advisory-only: it never fails `boost sync --check`.
  
- **`boost validate` advises on dangling legacy `$.<root>` conventions references.** A pre-token `$.slot` reference (e.g. `$.testing.runner`) is only ever *detected* by boost-core — never resolved — so it emits literally; and because the `## Project Conventions` block is CLAUDE.md-only, it dangles unresolved for every non-Claude agent. `boost validate` now surfaces each distinct legacy ref as a warning-level diagnostic pointing at the first emitted file it appears in, advising migration to a `boost:conv` token or an inlined value. Warning-level by design — it does not fail `--strict`, since a ref may be mid-migration. Detection is prose-scoped and inline-code-masked, so documented `$.slot` examples in the shipped migration skills are not flagged.
  
- **Keep-reason observability for the Project Conventions block (#87).** When the drop gate KEEPS the `## Project Conventions` block rather than dropping it, the engine now records WHY — the skill or guidance file carrying the legacy `$.<root>` ref, unresolved token, or prose pointer that pins it open (or a single no-migration-yet note for a pure-conventions project that hasn't adopted tokens). The gate decision is byte-identical; this is purely an additive provenance channel. Surfaced on three read surfaces: `boost sync` emits advisory INFO diagnostics (quiet unless `-v`), `boost doctor` adds a "Project Conventions block" section listing the reasons when kept, and `boost where --conventions` prints a `kept` / `dropped` / `not-applicable` / `status-unavailable` block-status line. An operator who migrated their skills to tokens but still sees the block can now find the one artifact holding it open instead of black-box probing.
  
- **`.config/boost/` runtime directory for the `.config/` layout.** When `boost.php` resolves under `.config/` (0.17.0's `.config/boost.php`), the sync ownership manifest now lives at `.config/boost/manifest.json` instead of the root `.boost/`, so all boost artifacts group under `.config/`. Migration is handled in BOTH directions (root ↔ `.config/`): the manifest reader prefers the active layout and falls back to the other layout's copy so prior ownership carries forward, and a real sync prunes the now-stale old-layout manifest (file + empty dir) so it never lingers unignored. Root-layout projects are completely unaffected.
  

### Internal

- `DoctorCommand` decomposition: the conventions-check and remote-skill report clusters moved into dedicated `ConventionsReporter` / `RemoteSkillsReporter` collaborators, dropping the command's cognitive-complexity baseline below 80 (entry removed). Behavior-preserving.
- `ValidateCommand` gained an `InstalledPackages` injection seam for test parity with `WhereCommand` / `DoctorCommand`.
- `SyncResult` gained `conventionsBlockKept`, `conventionsKeepReasons`, and `conventionsEvaluated` (the last distinguishes "gate ran and dropped" from "gate never ran" so block status is never misreported when a check-only sync carries benign advisories such as an uncached remote skill).
- The `boost where --conventions --json` shape is unchanged (a top-level list of slot rows); block status is a human-output addition only, to avoid breaking existing automation.
- Regression coverage added across the loaders, sync engine, doctor, where, conventions inliner, and manifest tests for every path above, including no-false-positive guards (assets, documented `$.slot` examples) and both-direction config-dir migration.

### Known limitation

`boost sync --check` does not report the one-time stale old-layout manifest cleanup after a config-location migration. The manifest is gitignored, regenerable, engine-internal state that has never been part of `--check` drift semantics; the next real sync resolves it.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.17.0...0.18.0

## [0.17.0](https://github.com/sandermuller/boost-core/compare/0.16.3...0.17.0) - 2026-06-01

<!-- verified-sha: d120f8f3743b23e778ac4d1da1b383ca8d0bf50c -->
`boost.php` can now live under `.config/` — the first step toward a tidier repo
root. Additive and fully backward-compatible: a root `boost.php` behaves exactly
as before, with no migration.

### Added

- **`.config/boost.php` as an alternative config location.** boost-core resolves
  its config from `boost.php` at the repo root OR `.config/boost.php`, via a single
  new `BoostConfigPath` resolver shared by every reader and writer. Exactly one may
  exist — having both is a hard, explanatory error (`AmbiguousBoostConfigException`)
  rather than a silent pick, so you never edit the file boost ignores. Source paths
  still resolve against the project root, so the two locations are fully
  interchangeable. `BoostConfigPath` is public, so wrapper packages inherit the same
  resolution.
  
- **`--config <path>`** on `sync`, `validate`, `where`, `doctor`,
  `convert-conventions`, `scan`, and `new` — point at an explicit config file. A
  relative path resolves against the project root (not the process CWD), so it's
  stable regardless of where boost is invoked from.
  
- **`boost install --config-dir`** scaffolds a new config at `.config/boost.php`
  instead of the root (root stays the default). When a config already exists, it is
  edited in place — boost never creates a second one.
  
- **`boost where` prints the resolved config path**, and **`boost doctor`** reports
  the config location up front: a both-files ambiguity surfaces as a clear "Config
  location" section, and a `.config/boost.php` whose explicit `__DIR__`-relative
  source path resolved under `.config/` (a silent empty-resolve) is flagged with a
  fix hint.
  

### Changed

- The scaffolded `boost.php` and the canonical config example no longer use
  `__DIR__`-relative source paths. Defaults are project-root-relative and
  location-independent; if you override a source path, use an absolute one —
  `__DIR__`-relative values break if the config file is later moved into `.config/`.

### Notes

Back-compat: every existing root-`boost.php` project behaves identically — no
migration, no new required config. The `.boost/` state directory and the emitted
agent files (`CLAUDE.md`, `.claude/`, …) are unchanged. Wrapper packages that want
to honor `.config/boost.php` should rebuild against `^0.17`; root-config consumers
need nothing.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.16.3...0.17.0

## [0.16.3](https://github.com/sandermuller/boost-core/compare/0.16.2...0.16.3) - 2026-06-01

<!-- verified-sha: 77277d9ed35712b8a8dce141a3b78b055f9138bb -->
A conventions-token leak-scan fix for symlinked host-shadow skills, a clearer
retired-path cleanup message, and an internal comment cleanup. Patch — `^0.16`
compatible, no consumer bump needed.

### Fixed

- **Conventions-token leak scan now sees symlinked host-shadow skills.** `boost doctor` and `boost validate --strict` scan the emitted skill set for leaked
  `<!--boost:conv …-->` tokens, but the enumeration did not descend directory
  symlinks. A host skill shadow served through a symlink (e.g.
  `.claude/skills/<name>` → `.ai/skills/<name>`) therefore hid any raw token in
  its `SKILL.md` from the scan — `boost doctor` reported clean while the agent
  read the literal token. The scan now follows the immediate skill-directory
  symlink, so the file it reports is the one the agent actually reads and a leak
  there surfaces. The traversal resolves only the one-hop shadow link and never
  follows links recursively, so a cyclic symlink under a skills directory cannot
  make the scan loop. boost still never WRITES through these consumer-owned
  symlinks; only the read-only scan follows them.

### Changed

- **Clearer retired-path cleanup message.** The diagnostic printed when boost
  removes a retired generated path (e.g. a former Copilot emit target) was
  rewritten in plain language — what was removed, why boost owns the path, and how
  to change emitted output — instead of the dense ownership-contract phrasing.

### Internal

- Trimmed historical noise from source comments (version stamps, issue references,
  prior-behavior narration) with no behavior change, and added regression guards
  pinning the conventions block's CLAUDE.md-only placement and the
  symlinked-shadow leak scan.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.16.2...0.16.3

## [0.16.2](https://github.com/sandermuller/boost-core/compare/0.16.0...0.16.2) - 2026-05-31

<!-- verified-sha: 56d09f1aa13d26d78b3a6082919b59bff1f093be -->
A cross-platform determinism fix, a vendor-author migration guide, and an internal
decomposition of the sync engine. (Rolls up the never-tagged 0.16.1 docs.)

### Fixed

- **Guideline emission order is now deterministic across platforms.** Emitted
  guidance files (CLAUDE.md / AGENTS.md / …) could come out with their guideline
  sections in a different ORDER on different filesystems (e.g. macOS APFS vs a Linux
  CI runner's ext4) — a pure reorder, identical headings, zero content change. For a
  project whose CI regenerates and commits boost output, that produced an infinite
  auto-fix loop: one platform writes one order, the other rewrites the other, each
  "fixing" the other forever. The resolver now emits a stable order — host-authored
  guidelines first (in their existing loader order, unchanged), then vendor /
  injected guidelines by `(vendor, source path)`. The fix is output-ordering only:
  it does not change which guideline wins a name collision, host-override shadowing,
  or any reported diagnostic — only the sequence in the written file, which is now
  byte-identical on every platform. (Surfaced from production dogfood; pairs with the
  wrapper packages' own source-ordering fix.)

### Added

- **`conventions-token-migration` skill** — a shipped, author-facing guide
  (`resources/boost/skills/`) for package authors migrating their skills/guidelines
  off `$.slot` conventions references onto render-time `<!--boost:conv …-->` tokens:
  the recipe, the `mode`×type matrix + prose-vs-fence placement, the authoring
  footguns (inline-code-wrapped tokens stay literal; an errored token ships raw; map
  sub-keys need `^0.16`), dropping the obsolete slot-table scaffolding, and the
  verify-before-ship workflow (`boost where --conventions` → sync both declared and
  unset states → `boost doctor` / `boost validate --strict`). Pins the consumer-floor
  rule for token-bearing skills. (Originally drafted for 0.16.1, which was never
  tagged.)

### Internal

- **SyncEngine decomposition** (behavior-preserving). The sync engine's conventions
  inlining + drop-gate, the 0.14 reconcile-on-sync orphan reap, and the wholesale
  markerless guidance write were extracted into focused collaborators
  (`ConventionsPass`, `OrphanReaper`, `GuidanceWriter`) backed by a shared filesystem
  utility (`ManagedFileOps`), cutting the engine's cognitive complexity by ~31% with
  no change to observable behavior — every step verified against the full suite +
  multi-run side-effect characterization (orphan reap, manifest ownership, the
  never-lossy empty-assembly guard). Closes cross-module helper duplication. No API
  change.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.16.0...0.16.2

## [0.16.0](https://github.com/sandermuller/boost-core/compare/0.15.0...0.16.0) - 2026-05-31

<!-- verified-sha: c36d913ef1b377c3dfb1f02fbde897b58c008310 -->
Conventions-token observability. When a `<!--boost:conv …-->` token does NOT resolve, a raw HTML-comment token lands in an emitted agent file — and the agent then reads the literal token instead of the value, silently. The dominant cause is a token-bearing vendor skill synced by a consumer still on boost-core **< 0.15**: the old engine has no inliner, so it copies the token verbatim with no error. 0.16.0 makes that leak visible at three escalating surfaces, and resolves a slot-addressing gap found while migrating real skills to tokens.

### Added

- **Conventions-token leak detection — three surfaces, one classifier.** Detection reuses the inliner's own line scanner, so what counts as a leak can never drift from how inlining works:
  
  - **`boost sync`** warns inline at render time, with a `file:line` locator — the leak is surfaced where it is born.
  - **`boost doctor`** runs an always-on, advisory scan of the emitted set — per-agent guidance files (CLAUDE.md / AGENTS.md / GEMINI.md) **and** per-agent `SKILL.md` files, including gitignored copies — and lists every leak with its cause. It never scans `.ai/` sources (those legitimately carry tokens).
  - **`boost validate`** turns each leak into an error diagnostic, so **`boost validate --strict` fails CI** on a leaked token. Canonical CI recipe: run `boost sync` (or `composer install`), then `boost validate --strict` over the post-sync emitted set.
  
- **Actionable causes.** A token that resolves cleanly yet sits raw on disk → "re-sync with boost-core ≥0.15" (it was emitted by an older engine, or the file is stale). A token that errors → the resolver's own message (unknown slot, type×mode mismatch, …). A surviving ````boost:conv` fence opener → the fence was never cleanly processed.
  
- **Open-vocab map sub-key resolution.** A token targeting a dynamic key of an `additionalProperties` map — e.g. `path="mcp.jira"` — now resolves. Previously it errored as "unknown slot" because resolution short-circuited when the schema had no statically-defined leaf for the key, so map sub-keys were unaddressable whether declared or defaulted. The resolver now descends into `additionalProperties` and sources a sub-key default from the nearest default-bearing ancestor map. Three-state resolution (declared → schema default → fallback) and declared-empty precedence are unchanged.
  

### Fixed

- **A leaked token inside an opt-in fence is no longer a blind spot.** A multi-line (`mode="yaml"`) token lives in a ````boost:conv`fence; on clean resolution the engine strips that`boost:conv` info-string. If a token inside the fence instead **errors**, the engine now **keeps** the info-string rather than stripping it — so the unresolved token stays detectable on disk by the same surviving-fence-opener signal that catches a pre-0.15 emit, with no risk of false-positiving on a documentation example in a plain code fence. A cleanly-resolved fence is byte-identical to before.

### Internal

- Detection is conservative by design: a token in prose (or a surviving opt-in fence) is a leak; a token in a plain code fence or an inline-code span is an intentional literal and is never flagged — so skills and guidelines that *document* token syntax in fenced examples don't trip the check. The fence-opener signal is decided by the full fence state machine, not a flat text match, so a `boost:conv` line nested inside another fence is correctly treated as content, not an opener.
- Engine-only and additive: no behavior change to a project with no tokens, and no new public API beyond the doctor check, the validate gate, and the shared scan classifier on the inliner.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.15.0...0.16.0

## [0.15.0](https://github.com/sandermuller/boost-core/compare/0.14.0...0.15.0) - 2026-05-31

<!-- verified-sha: 5fa2b0f947732a79b916c5da5197e8e58d03c115 -->
Conventions inlining. Project-convention values can now be resolved **into** the skills and guidelines boost-core generates — at sync time — instead of being rendered as a separate always-loaded `## Project Conventions` block in `CLAUDE.md`. Once a project's synced content is fully token-based, that block is dropped: the conventions still drive the output, they're just baked into the prose that needs them rather than carried as a standing context tax on every turn.

This is **Phase 1 (the engine)**. It's fully backward-safe: until a vendor skill actually uses a convention token, nothing changes — the block renders exactly as it did pre-0.15. Migrating vendor/host skills to tokens is a per-package, opt-in follow-up; the block auto-drops for each consumer as their skill set converges.

### Added

- **Render-time convention tokens.** A skill or guideline body may reference a convention slot with a token — `<!--boost:conv path="github.default_base_branch" mode="inline"-->` — and boost resolves it against the project's `withConventions([...])` declarations at sync time. Resolution is three-state by **path existence**: a declared value wins; otherwise the schema default; otherwise an inline `fallback`. A declared `false` or `[]` counts as *declared* (not missing), so an intentional empty list renders as `none` rather than silently falling through to a default.
- **Type × mode matrix.** Each slot renders in the mode that fits its shape: scalars as `inline`/`yaml`/`json`; scalar lists as comma-joined `inline`, `bullets`, `yaml`, or `json`; maps as `yaml`/`json`. A schema may pin the allowed render modes for a slot. A mode that doesn't fit the value's type is a render error.
- **The always-loaded block drops once a project is fully migrated.** When every synced skill and guideline gets its conventions from resolved tokens — and nothing still needs the runtime block, and no token errored — boost stops emitting the `## Project Conventions` section. The gate **fails toward keeping** the block: a legacy `$.slot` reference, an unresolved token, a prose pointer to "the conventions section above", or any render error all keep it. The scan is ownership-aware — it inspects only content that survives this sync, and it strips boost's own previously-rendered block before scanning so the heading can't keep itself alive.
- **`boost where --conventions`** (with `--json`). An on-request audit of every convention slot and where its effective value comes from: `declared`, `schema-default`, or `missing`. Surfaces what the inliner will resolve before you sync.

### Fixed

- **Convention render errors now fail `--check`.** An unknown slot, a type/mode mismatch, a multi-line value asked to render inline, or a slot with no resolvable value is reported as an error and **keeps** the block rather than emitting a half-resolved file — so a broken token can't silently ship degraded guidance.

### Internal

- Inlining runs over both vendor and host skills/guidelines. Tokens are recognized in prose and in fenced code (opt-in via a `boost:conv` info-string); inline-code spans, balanced-backtick runs, and an escaped `<!--\boost:conv-->` are left literal. Tokens are body-only — frontmatter is never substituted.
- The drop gate keys off the **live post-sync content set**, not just this run's emissions, and is ownership-aware: for a boost-owned guidance file it scans the residual outside boost's managed region; for a file boost doesn't own it scans the whole thing minus boost's own rendered block. This prevents both wrongly dropping the block while an operator-authored dependency is still live and wrongly keeping it on boost's self-reference.
- Phase 1 ships the engine only; no vendor skill emits tokens yet, so in practice the block continues to render until the skill packages migrate on their own cadence. The schema that governs slot shapes and render-mode pins is owned by the shared conventions schema package.

Spec and implementation were independently model-reviewed across multiple rounds and ratified by the maintainers of the dependent packages before merge. Sourced from production dogfood across the boost stack.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.14.0...0.15.0

## [0.14.0](https://github.com/sandermuller/boost-core/compare/0.13.0...0.14.0) - 2026-05-31

<!-- verified-sha: 49853fff176359a8ad862fd6463b80a35e17e5b7 -->
Reconcile-on-sync orphan reap. Using the 0.13.0 ownership manifest, boost-core now **removes the files it emitted once it no longer emits them** — closing the "delete it by hand" gap for project-scope output. Two cases land together: a **dormant FileEmitter** (its dep was removed, so it emits nothing) and a **de-selected agent's guidance file** (you dropped the agent from `withAgents(...)`). Both reap only what boost can prove it owns, and never touch operator-authored content.

### Added

- **FileEmitter dormancy reap.** When a `FileEmitter`'s backing dependency is removed (its `emit()` returns `null`, or the package is gone), the file it previously wrote — e.g. `.mcp.json` — is removed instead of left behind pointing at a dead tool. Emitter outputs are recorded in `.boost/manifest.json` as `category: file`, `provenance: emitter:<fqcn>`. Reaping is conservative and never-lossy:
  
  - an output is recorded as boost-owned only when boost **created it fresh** or **already owned it** — a first-time takeover of a pre-existing file you maintain is not claimed (and warns), so it's never reaped;
  - before deleting, the on-disk content is **sha-revalidated** — if you hand-edited the emitter's output (e.g. tweaked an `.mcp.json`), the divergence is detected and the file is **preserved**;
  - a **disabled** emitter (`withDisabledEmitters`) or one that **errored** this run keeps its file (disabling means "stop regenerating", not "delete");
  - only a regular file is removed — if you've since replaced the path with a directory or symlink, it's left alone;
  - a failed delete (permissions) **retains ownership** so the next sync retries rather than leaking.
  
- **Agent de-selection reaps the orphaned guidance file.** Removing an agent from `withAgents(...)` now removes that agent's now-stale `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` — but only when boost owns it (the on-disk sha still matches what boost wrote). A guidance file you've hand-edited is preserved. This closes the gap where a de-selected agent's guidance file lingered indefinitely while its skill directory was already pruned.
  
- **FileEmitter authoring guardrails.** An emitter may only write to a path it alone owns. A path that collides with a boost- or operator-owned surface — a guidance file (in any case spelling), `.gitignore`, `.boost/`, any agent's skill/command root, a source directory (`.ai/`, `resources/boost/`), or a wrapper-claimed path or its descendants — is rejected with a diagnostic and never written, tracked, or reaped. Emitter paths are canonicalized and case-folded before these checks, so `./CLAUDE.md` and `claude.md` can't slip through.
  

### Fixed

- **Redundant per-file entries in the managed `.gitignore` block.** When a wrapper injected skills into a directory boost already ignores at the directory level (e.g. `.claude/skills/`), boost-core also emitted a line for every individual `…/SKILL.md` beneath it — pure bloat that re-grew on every sync. The managed block now drops any per-file entry already covered by a directory-level pattern, keeping it compact. (Reported from `project-boost-laravel` adoption.)

### Internal

- The reap is a dedicated, **manifest-gated** pass — it consults the prior manifest's ownership rather than raw `.gitignore` membership, so the delete predicate matches the stated ownership contract by construction. Two-phase lifecycle is unchanged: decisions read the prior manifest, the new one is written last on a fully-successful sync; an absent manifest means exact pre-0.14 behavior (no new reaping).
- Emitter ownership is keyed by file identity (inode) where available, so a case-only output rename on a case-insensitive filesystem neither deletes the live file nor loses its ownership record; on filesystems without stable inodes this degrades to a benign, documented preserve-rather-than-reap.
- No Composer-plugin reintroduction. boost-core retired its plugin and runs as a library plus sync hooks, so cleanup is driven by reconcile-on-sync, not package-uninstall events. User/global-scope cleanup-on-remove remains deferred; the manifest's `scope` field already carries the forward-compatible schema for it.

Sourced from production dogfood and downstream adoption feedback across the boost stack.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.13.0...0.14.0

## [0.13.0](https://github.com/sandermuller/boost-core/compare/0.12.0...0.13.0) - 2026-05-31

<!-- verified-sha: dbaf956ace4a966416cf5840bd51d80d84a84e73 -->
A DX + observability minor anchored by a **sync ownership manifest**. boost-core now records what it emits — so it knows what it owns — which structurally resolves the ownership-signal tension behind the 0.12.0 empty-guard trade-off. Plus guideline-shadow parity in `boost where`/`boost doctor`, so a host guideline that silently shadows a vendor one is finally visible.

### Added

- **Sync ownership manifest (`.boost/manifest.json`).** On every successful sync boost-core writes a manifest of the files it emitted — each with a `sha256`, a `category` (`guidance`/`skill`/`command`), a `provenance` (`engine` or `wrapper:<vendor/package>`), and a `scope` (`project`/`user`). With ownership recorded, boost-core can safely clear or prune its own files without ever touching operator-authored content — replacing "guess from file content" with "consult the manifest." The manifest is **gitignored** via the managed `.gitignore` block (it's regenerable emit-state, not source). Absent a manifest — every pre-0.13 project, a fresh clone, the first 0.13 sync — behavior is exactly 0.12: no new clearing or pruning. The manifest only ever *enables* safe destructive actions; it never makes the no-manifest path more aggressive.
  
  - **Markerless guidance now converges safely.** An empty assembly clears a guidance file only when the manifest proves boost owns it (listed *and* the on-disk sha matches what boost last wrote). A file boost can't prove it owns — or one you've hand-edited since (sha diverged) — is preserved. The 0.12 empty-guard still holds for every non-owned file.
  - **Hand-edits are surfaced, never silently clobbered.** If you edit a boost-owned guidance file and a later non-empty sync would regenerate it, boost-core regenerates *and* warns — naming the file and pointing you at `.ai/guidelines/`, where durable content belongs. The prior content is always in git.
  - **Stale guideline files get pruned.** Tag-filtering away an agent's entire guideline set now prunes the orphaned guidance file when boost owns it (engine provenance, manifest-listed), instead of leaving it on disk indefinitely.
  - **Wrapper-emitted files are preserved across bare-CLI runs.** Paths tagged `wrapper:<vendor/package>` are never pruned by a bare-CLI sync (which can't reproduce a wrapper's injection set); wrapper-path cleanup defers to the next wrapper-driven sync. The 0.11 `BoostWrapperContract` stays as the cold-start fallback for the manifest-absent window.
  
- **`boost where` guideline-shadow parity.** A host `.ai/guidelines/<name>.md` that shadows an allowlisted vendor guideline of the same name is now annotated in `boost where` output (`(shadows <vendor>)`), counted in the shadow NOTE, and diffable via `--diff=<guideline-name>`. The shadow check respects the active `withTags(...)` filter: a vendor guideline that wouldn't emit anyway (tag-filtered) is not reported as shadowed, and `--diff` resolves against the tag-eligible vendor copy — no contradictory false positives. Brings guidelines to parity with the existing skill-shadow surfacing.
  
- **`boost doctor` reports guideline shadows.** Host→vendor guideline shadows now surface in `doctor` too, reusing the same computation as `where`, so the two agree on the shadow story.
  

### Fixed

- **Conventions render independently of the active agent set.** `->withConventions([...])` writes the Project Conventions section to `CLAUDE.md` even when the Claude agent isn't in `withAgents(...)` — a Codex/Copilot/Gemini-only project that declares conventions no longer needs Claude active to get them rendered.

### Internal

- New `src/Sync/SyncManifest.php` value object owns manifest read/write/compare, the category-specific ownership rule (guidance is listed-and-sha-matched; skills/commands listed), and the source-dir exclusion invariant (a manifest never lists, and a prune never resolves, a path under `.ai/` or `resources/boost/` — protecting dual-role publisher repos whose shipped product lives there).
- `SyncEngine` reads the **prior** manifest at the start of a sync for all destructive decisions and writes the **new** manifest last, only on a fully successful, non-render-failed sync — so a first 0.13 sync can't promote a pre-existing file to owned mid-run, and a partial/failed sync leaves the last-known-good manifest untouched. Destructive clear/prune runs only after all non-destructive writes succeed.
- Wrapper provenance is attributed by path-matching each emitted path against the injecting wrapper's `injectedEmitPaths()` (via `WrapperEmitDiscovery`), not by injection vendor-key — so the manifest tags the injector, the intersection of emitted-and-declared paths, and stays correct under multiple installed wrappers.
- The no-conventions-schema INFO is dormancy-gated; `boost doctor` reuses a single drift sync for both drift and shadow reporting.

The `boost install` composer.json script scaffold considered for this release was deferred — safely scaffolding a dev-only plugin's sync into Composer lifecycle hooks proved intractable across `--no-dev` and bare-CLI install paths, and is largely redundant with the plugin's existing auto-sync. It returns as a focused follow-up if real demand appears.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.12.0...0.13.0

## [0.12.0](https://github.com/sandermuller/boost-core/compare/0.11.0...0.12.0) - 2026-05-30

<!-- verified-sha: cdd836aa71e696799f3bf6033469621256eb62f3 -->
Markerless agent-guidance files. The agent-guidance files boost-core emits — `CLAUDE.md`, `AGENTS.md`, `GEMINI.md` — are now **wholesale boost-owned and carry no markers**. boost-core regenerates each file in full on every sync from your `.ai/guidelines/` sources and `boost.php` conventions. The `<!-- boost-core:guidelines:* -->` / `<!-- boost-core:conventions:* -->` marker pairs are gone: fewer moving parts in the emitted file, and one consistent ownership model across every path boost-core manages.

### Changed (action may be required)

- **Agent-guidance files are markerless and wholesale-owned.** Put operator-authored guidance in `.ai/guidelines/` (it's assembled into the file on every sync) rather than hand-editing the emission target. On the first 0.12.0 sync of a legacy marker-bounded file, the markers are stripped and any genuine content outside them is preserved **once** below the generated body, with a warning pointing you at `.ai/guidelines/`. Nothing is silently lost.
- **Legacy Project Conventions YAML.** If a file still carries a `boost-core:conventions:*` marker block, migrate it into `boost.php` with `vendor/bin/boost convert-conventions` **before** upgrading (while the markers still exist). After a 0.12.0 sync has stripped the markers, copy any preserved conventions YAML into `boost.php`'s `->withConventions([...])` chain by hand — `convert-conventions` requires the markers and no longer applies once they're gone.
- **Track your guidance files.** Keep `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` in version control so boost-core's wholesale output is reviewable in diffs and recoverable from git. boost-core's managed `.gitignore` block already keeps them out of the ignore list.

### Added

- **Empty-assembly guard.** Sync never blanks a non-empty guidance file. When boost resolves no guidelines and no conventions, an existing non-empty `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` is left untouched (an INFO records this) instead of being overwritten with empty content. Adopting boost-core in a repo that already has a hand-written or `boost install`-generated `CLAUDE.md` — including via auto-sync on a routine `composer update` — no longer risks wiping it. Delete the file manually if you genuinely want it empty. Legacy marker-bounded files are exempt: they're provably boost-written, so they still converge.

### Fixed

- **Conventions render independently of the active agent set.** `->withConventions([...])` once again writes the Project Conventions section to `CLAUDE.md` even when the Claude agent itself isn't in `withAgents(...)` — matching pre-0.12 behavior. A Codex/Copilot/Gemini-only project that declares conventions no longer loses them.
- **Quieter sync for skills-only vendors.** The "N of M allowlisted vendor(s) ship no conventions-schema.json" INFO is now dormancy-gated: it's suppressed when conventions aren't declared *and* no vendor ships a schema, so a skills-only vendor in a project that doesn't use conventions stops producing worrisome noise. It still surfaces when you've declared conventions (a missing schema is then actionable) or when any vendor ships a schema file — including a malformed one, which still proves the conventions subsystem is in play.

### Internal

- New `GuidanceComposer` owns markerless assembly and the one-time legacy-marker migration (strip the guidelines region, unwrap the conventions region preserving its YAML, drop stale inline duplicates, preserve genuine residual once + warn).
- `SyncEngine` consolidates the guideline and conventions writes into a single wholesale markerless write per unique guidance file; `AgentTarget::plan()` now emits skill writes only.
- `boost doctor` flags bare-name exclude keys (those missing a `vendor/package:` prefix) that would otherwise silently no-op.

Sourced from production dogfood and adoption feedback across the boost stack.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.11.0...0.12.0

## [0.11.0](https://github.com/sandermuller/boost-core/compare/0.10.3...0.11.0) - 2026-05-30

<!-- verified-sha: e761cd506f1857352ba03e310bde70f6b42d7cb3 -->
**Drift-comparison wrapper-injection awareness.** Closes the correctness half of the wrong-entry-point bug class. 0.10.0 closed the discoverability half (the `boost doctor` entry-point-mismatch banner); 0.11.0 stops bare-CLI `boost sync` from false-positive-flagging wrapper-injected files for deletion.

### Why this matters

When a wrapper package (`sandermuller/project-boost-laravel`) is the install pipeline, it injects skills and guidelines at sync time via `SyncEngine::sync()`'s `injectedVendorSkills` / `injectedVendorGuidelines` runtime args. The wrapper's canonical entry point (`php artisan project-boost:sync`) writes those files to disk.

A bare-CLI `boost sync` / `boost sync --check` carries no injection args, so the resolve pass produces an empty vendor-skill set. The generic stale-file cleanup pass (0.9.1) then classifies every previously-injected file as stale-to-delete:

```
[WARNING] Drift detected: 34 file(s) would change.
  - .agents/skills/fluxui-development/SKILL.md
  - .agents/skills/inertia-svelte-development/SKILL.md
  ... (30 wrapper-injected SKILL.md files marked for deletion)
















```
Sourced from production dogfood: a downstream Laravel consumer added a workflow-rule note to their CI explicitly forbidding `boost sync` invocations — documenting a workaround for what should be an engine guarantee.

### Added

#### `BoostWrapperContract` — wrapper emit-surface declaration

Wrapper packages declare a `BoostWrapper` class implementing `SanderMuller\BoostCore\Contracts\BoostWrapperContract` at any of their PSR-4 prefixes:

```php
namespace SanderMuller\ProjectBoostLaravel;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

final class BoostWrapper implements BoostWrapperContract
{
    /** @param  list<string>  $activeAgents  agent enum values in withAgents(...) */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        return ['.agents/skills/some-injected-skill/SKILL.md', /* ... */];
    }
}
















```
`boost sync` reads the declared emit-paths and excludes them from stale-file cleanup, so bare-CLI sync no longer flags wrapper-injected files for deletion. The `$activeAgents` argument carries the project's `withAgents(...)` set so wrappers compute the correct per-agent paths (`.claude/skills/…` for Claude Code, the shared `.agents/skills/…` pool for Cursor / Copilot / Codex / etc.) using boost-core's `AgentTarget` API.

The declared paths also land in the boost-managed `.gitignore` block, so bare-CLI sync doesn't drop them from gitignore tracking (which would leak wrapper-emitted files into the operator's git working set).

### Behavior

- **Wrapper absent / no `BoostWrapper` class** — bare-CLI sync falls back to strict drift comparison (correct, just noisier). No engine-side wrapper allowlist to maintain; the 0.10.0 entry-point banner remains the discoverability surface.
- **Per-package failure isolation** — a wrapper whose class fails to autoload (parse error / top-level throw), throws from `injectedEmitPaths()`, returns a wrong type, or declares the class without implementing the contract degrades that one package with a diagnostic, never aborts the sync.
- **Directory claims** — a wrapper claiming a directory (`.agents/skills/foo`) preserves every file under it via prefix-match.
- **Guideline files excluded** — `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` are filtered from the wrapper exclusion: they use marker-bounded managed regions with operator-tracking, not wholesale replacement, so the engine never routes them through file-level cleanup.

#### Scope

The contract covers stale-file-cleanup exclusion only. It does **not** regenerate wrapper-injected managed-region *content* (guideline sections injected via `injectedVendorGuidelines`) on a bare-CLI run — that content is not reproducible without the wrapper's runtime injection args. Bare-CLI runs that need the full injected content must use the wrapper's canonical entry point; `boost doctor`'s entry-point-mismatch banner points operators there.

### Compatibility

`composer update sandermuller/boost-core` to `^0.11`. No `boost.php` or config changes required for direct consumers — the engine-side change is backward-compatible (wrapper-absent projects keep the prior behavior).

Wrapper maintainers opt in by shipping the `BoostWrapper` class. This is the first boost-core minor that adds a **wrapper-contract surface** (engine + wrapper coordinate via a discovery contract) rather than a purely engine-internal change — wrapper packages need a paired release to gain the precision; direct consumers absorb without action.

`sandermuller/project-boost-laravel` ships its `BoostWrapper` implementation in the paired release; until then, bare-CLI sync on a project-boost-laravel project falls back to strict drift comparison (the pre-0.11.0 behavior, surfaced by the entry-point banner).

### Internal

- `SanderMuller\BoostCore\Contracts\BoostWrapperContract`: new interface, static `injectedEmitPaths(string $projectRoot, array $activeAgents): list<string>`.
- `SanderMuller\BoostCore\Sync\WrapperEmitDiscovery`: probes each installed package's PSR-4 prefixes for a `BoostWrapper` class, package-scopes the resolved class via a `ReflectionClass` file-path check (filters foreign classes occupying the same FQN), unions `injectedEmitPaths()` across all wrappers, canonicalizes returned paths (forward slashes, collapse `.`/duplicate-separator segments, reject `..` traversal), and degrades per-package with diagnostics on every failure mode.
- `SyncEngine::cleanupStaleManagedFiles()`: excludes wrapper-claimed paths (exact + directory-prefix match). `SyncEngine::updateGitignore()`: includes them in the managed block, filtering guideline-file basenames.
- Also bundles the 0.11.0 `Likely cause:` wording pin — an exact-string regression assertion on the 0.10.2 residual-warning's non-exhaustive-hypothesis-set framing.
- PHPStan baseline: SyncEngine cognitive complexity 210 → 222.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.10.3...0.11.0

## [0.10.3](https://github.com/sandermuller/boost-core/compare/0.10.2...0.10.3) - 2026-05-29

<!-- verified-sha: 9187c7ce291f7465cd2f929a56f2eff602cdee5c -->
**Symlink-aware cleanup.** Closes the residual-path failure mode the 0.10.2 observability surfaced. The cleanup pass for retired Copilot paths now correctly handles symlinks left over from pre-0.9.6 boost-core's explicit symlink-emit shape, instead of silently leaving them on disk.

### Why this matters

Boost-core's retired-paths cleanup walks `.github/skills/` (retired in 0.9.1) on every Copilot-active sync and removes the directory and its contents. Before 0.9.6, boost-core explicitly emitted symlinks at this path for vendor-shipped skills:

```
.github/skills/mcp-development -> ../../vendor/laravel/mcp/resources/boost/skills/mcp-development

















```
This was the older Copilot path-consumption route for `laravel/mcp`-shipped skills. Operators who upgraded across the 0.9.x line carry these symlinks until cleanup removes them.

The cleanup walker was failing on those symlinks:

- `SplFileInfo::isDir()` follows symlinks → reports a symlink-to-directory as a directory.
- The engine then called `@rmdir` on the symlink, which fails because `rmdir` requires an actual directory.
- The symlink stayed on disk; the parent `.github/skills/` couldn't be removed while still containing the symlink; drift persisted across syncs.

0.10.2 made this failure visible by surfacing residual paths in a warning diagnostic. 0.10.3 fixes the underlying cause.

### Fixed

#### `deleteRecursive` checks `is_link()` before `is_dir()`

In both the top-of-method dispatch (defensive — guards against a future caller passing a symlink as the cleanup root) and the iteration loop body, symlinks are now detected before the directory check and removed with `unlink()` instead of `rmdir()`. The change preserves PHP's default iterator behavior: `RecursiveDirectoryIterator::hasChildren()` defaults to `$allowLinks=false`, so the iterator never descends INTO symlinked directories — vendor content beyond the symlink target stays untouched.

Regression coverage asserts both halves of the contract: the retired symlink IS removed, and the vendor target IS preserved (`vendor/laravel/mcp/.../skills/mcp-development/SKILL.md` survives with original content).

### Compatibility

`composer update sandermuller/boost-core` to `^0.10`. No `boost.php` or config changes required. No breaking surface.

Operators upgrading from 0.10.0 or 0.10.1 who carry pre-0.9.6 symlinks will see them cleaned up on next sync. Operators on 0.10.2 who saw the residual-warning diagnostic will see the residuals removed and the warning stop firing.

### Internal

- `SyncEngine::deleteRecursive()`: `is_link()` check added at top of method + in the iteration loop body, ordered before `is_dir()` since the latter follows symlinks. Vendor-content-preservation contract pinned by iterator default + regression test.
- Regression test: POSIX-only (skipped on Windows where symlink creation requires admin), uses the exact reproducer shape — `.github/skills/<name>` symlink pointing at vendor content, asserts symlink removal + vendor preservation + no residual-warning emission.
- PHPStan baseline: SyncEngine cognitive complexity 205 → 210.

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline:

```bash
gh release create 0.10.3 \
    --target main \
    --title "v0.10.3" \
    -F internal/release-notes-0.10.3.md

















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.10.2...0.10.3

## [0.10.2](https://github.com/sandermuller/boost-core/compare/0.10.1...0.10.2) - 2026-05-29

<!-- verified-sha: e3dcf58d1f572462d51da4dc4d92ee43f4e63233 -->
### Why this matters

Boost-core's path-ownership contract (0.9.6+) cleans up retired Copilot paths (`.github/copilot-instructions.md`, `.github/skills/`) on every Copilot-active sync. Cleanup is best-effort fs work — `@unlink` and `@rmdir` can fail silently for legitimate reasons:

- Restrictive directory permissions inherited from a clone or CI build step.
- Open file descriptors holding entries on Windows (and occasionally on macOS).
- Races with concurrent emitters writing into the same subtree.

Before 0.10.2, all three failure modes produced the same visible signal as a successful cleanup. Operators observed:

- ✅ "Cleanup: removed retired boost-core path `.github/skills`"
- 🔁 Immediately after: `boost sync --check` re-flagged drift on `.github/skills`
- 🤷 No signal what failed or how to fix it

`SyncResult::hasDrift()` and the `deleted=` count returned by the engine also lied in that case — wrapper-side consumers reading the write log treated the cleanup as complete, so any downstream tooling that gated on "did sync make changes" was working from a corrupted signal.

### Fixed

#### Cleanup failures surface as a warning naming residual paths

`deleteRecursive` + `cleanupPath` now accumulate failed paths through a by-reference list. When any deletion fails, `cleanupStalePaths` emits a warning diagnostic naming up to 5 residual paths (with a `+N more` overflow tail for larger sets):

```
⚠ Cleanup of `.github/skills` left 3 residual path(s) on disk — drift will
  persist until removed manually. Likely cause: permission denied, open file
  descriptor, or concurrent re-emission. Residual: .github/skills/locked-bundle,
  .github/skills/locked-bundle/SKILL.md, .github/skills


















```
Operators get a concrete fix path (`chmod`, identify the holding process, retry sync) instead of opaque persistent drift.

#### Failure-aware write log

The path is no longer tagged `DELETED` in `SyncResult::writes` when residuals remain. `hasDrift()`, the `deleted=` summary count, and wrapper-side consumers reading the write log now reflect the on-disk reality. The success-shaped INFO is also suppressed on failure, so cleanup attempts never produce contradictory diagnostics for one path.

### Compatibility

`composer update sandermuller/boost-core` to `^0.10`. No `boost.php` or config changes required. No breaking surface.

`SyncResult::hasDrift()` and the deleted-count semantics are now strictly more accurate for the failure case. Wrapper packages that gated on these signals were already working with the right intent — this release just makes the signals match reality when cleanup partially fails.

### Internal

- `SyncEngine::deleteRecursive()` / `cleanupPath()`: by-reference `$failures` accumulator threaded through the recursion. Default `&$failures = []` keeps callers backward-compatible (the discarded temporary array is acceptable — failure-aware callers pass their own).
- `SyncEngine::cleanupStalePaths()`: split branches on `$failures === []` — success path appends the write + INFO, failure path emits only the warning.
- Regression coverage: realistic multi-bundle SKILL fixture (matching `mcp-development/SKILL.md` + `references/api.md` layout) for the happy path; POSIX permission-locked subdir reproduces the `@`-suppressed failure mode and asserts both the warning surface and the absence of the false-positive INFO + `hasDrift()` lie. The permission test is skipped on Windows (different fs permissions model) and on root (bypasses checks).
- dev-deps: `sandermuller/boost-skills` bumped `^1.6` → `^1.9.3` for current dogfood.
- PHPStan baseline: SyncEngine cognitive complexity 197 → 205.

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline:

```bash
gh release create 0.10.2 \
    --target main \
    --title "v0.10.2" \
    -F internal/release-notes-0.10.2.md


















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.10.1...0.10.2

## [0.10.1](https://github.com/sandermuller/boost-core/compare/0.10.0...0.10.1) - 2026-05-29

<!-- verified-sha: 6680098484305ae8475601fb2ac3e45ad0ff1b79 -->
**Polish patch.** Two diagnostic-surface improvements landed together: schema-discovery INFO-noise collapse + read-only `boost doctor --check-stale-paths` audit. Both surfaced from adoption dogfood and codex-review during the 0.10.0 cycle; bundled here as a tight cleanup before 0.11.x.

### Why this matters

`vendor/bin/boost sync` is invoked on every `composer install` / `composer update` through the package's auto-sync hook. Any diagnostic that fires routinely there competes with signal for operator attention. Two operator-visible regressions had accumulated:

1. **Schema-discovery noise.** Allowlisting N vendors that don't ship `conventions-schema.json` produced N per-vendor INFO diagnostics per sync. Conventions-schema is a niche feature; most allowlisted vendors don't ship one. Sync output inverted: more noise than signal.
   
2. **No read-only audit for the retired-paths registry.** Boost-core's path-ownership contract (0.9.6+) cleans up retired Copilot paths (`.github/copilot-instructions.md`, `.github/skills/`) on every Copilot-active sync. Operators upgrading across the 0.9.0 / 0.9.1 retirement boundaries had no way to see "what would `boost sync` delete on my project" without running sync itself.
   

### Added

#### `boost doctor --check-stale-paths`

Opt-in audit of the retired-paths registry. Reports paths boost-core has emitted in past versions but no longer maintains, that still exist on disk. Read-only by contract — sync owns deletion; doctor owns reporting.

Output (operator triage example):

```
Stale paths (retired-paths registry)
====================================

Retired paths still present on disk. Next `vendor/bin/boost sync` will delete:
 * .github/copilot-instructions.md
 * .github/skills



















```
When Copilot is not in the project's active agents, the audit surfaces `Copilot not in active agents. Retired-paths registry is Copilot-scoped — nothing to audit.` rather than silently returning clean, since a non-Copilot project may have `.github/skills/` from an unrelated source that boost-core has no intent to delete.

Registry extracted to a single source of truth (`SyncEngine::RETIRED_COPILOT_PATHS`) so sync's cleanup pass and doctor's read-only audit can never drift from each other.

### Changed

#### Schema-discovery INFO-noise collapse

`SchemaDiscovery::discover()` previously emitted one INFO diagnostic per allowlisted vendor that ships no `conventions-schema.json`. Collapse to a single summary INFO naming the count + a pointer to `boost doctor` for the per-vendor list:

```
ℹ 3 of 4 allowlisted vendor(s) ship no conventions-schema.json. Inspect
  `boost doctor` vendor allowlist section for the per-vendor list.



















```
The all-clean case (every allowlisted vendor ships a schema) stays silent — no diagnostic at all, so the rare-but-clean signal is preserved.

`DoctorCommand::reportConventions` now filters info-level diagnostics from the malformed-declaration branch. A 0.10.1-draft regression (caught by codex-review pre-ship) would have false-positive-triaged the legitimately-empty "no schemas published yet" case as "all declarations malformed", since the noise-collapse summary populated the diagnostics list. Level-aware branching keeps malformed triage strict.

### Compatibility

`composer update sandermuller/boost-core` to `^0.10`. No `boost.php` or config changes required. No breaking surface.

The summary diagnostic's message text is now stable for the duration of the 0.10.x line — automation parsing sync output (rare; the diagnostic channel is for humans) should match on `ship no conventions-schema.json` rather than the exact "N of M" framing.

### Internal

- `SyncEngine::RETIRED_COPILOT_PATHS`: new public const. Registry shared between sync's `cleanupStalePaths()` and doctor's `reportStalePaths()`.
- `DoctorCommand::reportStalePaths()`: new section method; gated on `--check-stale-paths` flag + Copilot in active agents.
- `SchemaDiscovery::discover()`: accumulates no-schema vendor names then emits one summary diagnostic; self-referential boost-core skip excluded from the denominator.
- `DoctorCommand::reportConventions()`: info-level filter on the empty-sources branch.
- 5 new regression tests pinning the noise-collapse behavior + the mixed-allowlist wording guard + the no-schemas-published triage guard.
- PHPStan baseline complexity bumped DoctorCommand 91 → 100.

### What's NOT in this release

Deferred from 0.10.1 scope as separate cycles:

- **Residual-subdir survival in `deleteRecursive` cleanup pass** — empirical report from project-boost-laravel adoption: after `boost sync` cleaned up `.github/skills`, a residual subdirectory survived on disk; `boost sync --check` flagged drift indefinitely. The doctor `--check-stale-paths` flag added in this release surfaces the issue read-only as a side-effect (re-flags on every invocation), which is the right interim behavior. Deletion-path fix is a 0.10.2 candidate once the failure mode is instrumented.
- **Drift-comparison wrapper-injection awareness** — bare-CLI `boost sync --check` reports 30+ wrapper-injected SKILL.md files as drift when project-boost-laravel is the install pipeline. The 0.10.0 entry-point banner closes the discoverability gap (operator gets clear "do-not-run bare CLI" guidance) but bare sync still reports incorrect deletion intent. Drift comparison needs to read wrapper-injected canonical state when the wrapper is detected; non-trivial engine change, 0.11.x scope.

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline:

```bash
gh release create 0.10.1 \
    --target main \
    --title "v0.10.1" \
    -F internal/release-notes-0.10.1.md



















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.10.0...0.10.1

## [0.10.0](https://github.com/sandermuller/boost-core/compare/0.9.7...0.10.0) - 2026-05-29

<!-- verified-sha: 01838ec8d4e7c2191283e2844fc8d814f31383f2 -->
**Wrong-entry-point cycle.** Cross-agent capability asymmetry was the bug class — surfaced empirically by adoption dogfood on a Laravel project. The minor closes the bug class from two engine-side surfaces, both gated on `sandermuller/project-boost-laravel` presence in the installed package set.

### Why this matters

Operators on Laravel projects who wired `BoostAutoSync::run` into `composer.json` (the bare-CLI hook) experienced **silent cross-agent skill loss**:

- **Claude Code** worked fine — laravel/boost's MCP server delivers bundled skills at runtime, masking the absence locally.
- **Cursor / Copilot / Codex** silently missed bundled skills (pest-testing, livewire-development, filament-development, inertia-vue, inertia-react, fluxui-development, volt-development, tailwindcss-development, wayfinder-development, laravel-best-practices) because file-fanout was their only delivery mechanism + the bare-CLI invocation bypassed the wrapper's skill-injection pipeline.

The cross-agent asymmetric symptom hid the bug from operators using Claude Code as their primary agent. The actual capability gap was invisible until they pivoted to another agent and noticed something missing — which is rarely, in practice. Single-source file fanout is a **cross-agent-coverage requirement**, not just a skill-count concern.

### Added

#### `boost doctor`: Entry-point mismatch banner

When `sandermuller/project-boost-laravel` is installed alongside boost-core, `boost doctor` now emits a banner-warning explaining the cross-agent capability asymmetry + recommending `@php artisan project-boost:sync` as the canonical entry point.

The banner does NOT fire for non-Laravel projects (project-boost-laravel not installed → no banner). Architectural ownership preserved: boost-core stays framework-agnostic; the detection routes operators to project-boost-laravel's canonical guidance rather than embedding Laravel-opinionation in the engine.

#### `boost tags` / shared tag-reporter: three-case diagnostic split

The "Declared tags matched by no installed skill or guideline (possible typo)" diagnostic was a single message collapsing three distinct probable root causes into one wording. The split surfaces the right fix path for each case:

| Case | Detection | Fix path |
|---|---|---|
| **1. Actual typo** | Near-duplicate name detection across declared + installed tags | Correct the spelling |
| **2. Bare-CLI without wrapper-injection** | `project-boost-laravel` installed + (any declared tags unused OR no skills loaded at all) | `@php artisan project-boost:sync` |
| **3. Forward-compat declaration** | Wrapper NOT installed + declared tags unused | Harmless — declared tags survive across `boost install` re-runs even when no installed vendor publishes a matching skill |

Detection ordering: typo (cheapest) → bare-CLI (most specific fix path) → forward-compat (catch-all). The case-2 wording explicitly names the cross-agent-asymmetry mechanism so operators triaging via their primary-agent experience understand why the diagnostic fires.

Case-2 also fires at the empty-skills-loaded gate (the exact symptom an adoption-dogfood bare-CLI repro produced: "No allowlisted vendor skills or guidelines installed" with project-boost-laravel installed → previously read as legitimately-empty, now correctly surfaces the entry-point gap).

### Architectural ownership

The diagnostics route operators to project-boost-laravel's canonical guidance rather than embedding Laravel-opinionation in the engine. boost-core does NOT scaffold `composer.json` scripts in `boost install` — that's a 0.11.x candidate if the doctor-banner + diagnostic-split signal doesn't sufficiently close the new-consumer gap empirically.

The framework-agnostic ownership boundary is preserved:

- **boost-core**: detects the wrapper's presence + routes operators to the wrapper's guidance
- **project-boost-laravel**: owns the Laravel-opinionated install + canonical hook + render semantics
- **README** (engine-side): qualifier section added in 0.9.7 directing Laravel + project-boost-laravel consumers to the artisan path

### Compatibility

`composer update sandermuller/boost-core` to `^0.10`. No `boost.php` or config changes required.

Per the load-bearing-only floor-pin discipline, this is a load-bearing patch (closes a real silent-capability-loss class of bug). Downstream packages may floor-bump to `^0.10` for the cross-agent-symmetry guarantee. **For Laravel + project-boost-laravel consumers**: if your `composer.json` `scripts` currently wires `BoostAutoSync::run`, swap to `@php artisan project-boost:sync` per the doctor banner's recommendation. The cross-agent symmetry gain reaches Cursor / Copilot / Codex / etc., which the bare-CLI path silently missed.

### Internal

- `DoctorCommand::reportEntryPointMismatch()`: new section method; gated on `sandermuller/project-boost-laravel` in InstalledPackages. Two regression tests.
- `TagReporter::report()` + `renderHygiene()`: three-case split logic. Test fixture includes wrapper-installed + wrapper-absent scenarios with declared-but-unused tag cohorts.
- PHPStan baseline complexity bumped DoctorCommand 90 → 91 (banner method added).

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline:

```bash
gh release create 0.10.0 \
    --target main \
    --title "v0.10.0" \
    -F internal/release-notes-0.10.0.md




















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.7...0.10.0

## [0.9.7](https://github.com/sandermuller/boost-core/compare/0.9.6...0.9.7) - 2026-05-29

### Fixed

#### Self-referential conventions-schema noise guard

`SchemaDiscovery::discover()` now skips `sandermuller/boost-core` self-reference before the schema-presence check fires. Self-allowlisted projects (dogfood + tooling-author projects that include boost-core in their own `withAllowedVendors([...])`) were emitting a noise INFO diagnostic for boost-core's own absence-of-schema on every sync — `ℹ vendor sandermuller/boost-core is installed but ships no conventions-schema.json at .../boost-core/resources/boost/conventions-schema.json`.

boost-core is the engine, not a catalog. It ships no `conventions-schema.json` by design. Absence-of-schema for the engine itself is not signal — it's a tautology. The guard removes one noise line per sync for self-allowlisted projects.

Regression test in `tests/Unit/Conventions/SchemaDiscoveryTest.php` asserts the guard fires + that non-engine vendors in the allowlist still produce their legitimate INFO diagnostics when they don't ship a schema (the genuine-signal case is unaffected).

### Documented

#### BoostAutoSync silence-as-success semantic

`BoostAutoSync::run` docblock + the README's `BoostAutoSync` section now document the silence-on-no-op design intent explicitly:

> **No output = success.** `BoostAutoSync::run` stays silent on a true no-op (`wrote=0, deleted=0`) by design — the hook fired, the sync ran, nothing changed. Output appears only when something actually changed or an error surfaced. If you want a positive "ran OK" confirmation on every install (helpful when debugging "did the hook fire?"), use `BoostAutoSync::runWithSummary` instead.

Operator-side uncertainty ("did the hook actually fire on this `composer install`?") traced to a docs gap, not an engine bug.

#### Laravel-context qualifier in BoostAutoSync section

README's `BoostAutoSync` section now adds an explicit qualifier for consumers on Laravel + [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel):

> **If you're on Laravel + `project-boost-laravel`**, use `@php artisan project-boost:sync` instead of `BoostAutoSync::run`. The artisan-wrapped path runs through the Laravel container, which bootstraps `BladeRenderer` correctly + delivers laravel/boost's bundled skills to every active agent (Cursor / Copilot / Codex / etc.). The bare-CLI path bypasses both — Claude Code may mask the absence locally via the MCP server, but the cross-agent skill set silently misses the bundled skills.

The architectural ownership boundary is preserved: boost-core stays framework-agnostic; `project-boost-laravel` owns Laravel-opinionation. The README's job is to flag the relevant case + cross-reference the canonical guidance. boost-core does not scaffold composer.json scripts; that's a deferred 0.11.x candidate if the doctor-banner work queued for 0.10.0 doesn't sufficiently close the new-consumer gap.

### What to expect on first sync

For consumers absorbing 0.9.0 → 0.9.7 across multiple patches:

| State | What sync does |
|---|---|
| You declared `withConventions([...])` in `boost.php` | CLAUDE.md re-renders from boost.php's source-of-truth (0.9.0 source-flip). |
| Legacy `.github/copilot-instructions.md` tracked on disk | Auto-deleted unconditionally when Copilot is in active agents (0.9.6 path-ownership). |
| Legacy `.github/skills/` tracked on disk | Same — auto-deleted (0.9.6). |
| You self-allowlisted `sandermuller/boost-core` | One fewer INFO diagnostic line per sync (0.9.7 self-referential guard). |
| You added `BoostAutoSync::run` to `composer.json` scripts | No output on no-op installs (designed silence-as-success). |

If your CLAUDE.md ends up in an unexpected state after sync (stale slot values, missing guideline content, marker-region body that doesn't match boost.php), the canonical recovery is:

```bash
rm CLAUDE.md && vendor/bin/boost sync





















```
`boost.php` and `.ai/` are authoritative — the rendered file is derived. Removing it is non-destructive; the next sync re-renders from canonical sources.

### Compatibility

Compatible with `boost-core ^0.9`. Consumers on `0.9.0` through `0.9.6` upgrade by `composer update sandermuller/boost-core` — no `boost.php` or config changes required. Per the load-bearing-only floor-pin discipline, this is a polish-tier patch — downstream packages absorb it transitively via existing range constraints without floor-bumping.

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline:

```bash
gh release create 0.9.7 \
    --target main \
    --title "v0.9.7" \
    -F internal/release-notes-0.9.7.md





















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.6...0.9.7

## [0.9.6](https://github.com/sandermuller/boost-core/compare/0.9.5...0.9.6) - 2026-05-29

Path-ownership reframe. Replaces the marker-presence guard introduced in 0.9.1 with a clean path-ownership contract for retired AI-agent paths. The reframe surfaced from cross-peer design challenge after consumer adoption reported the marker-guard skipping cleanup on a pre-0.8.2 wholesale-sync file indefinitely.

### Fixed

#### Retired AI-agent paths cleaned unconditionally

`SyncEngine` no longer requires the `<!-- boost-core:guidelines:start -->` marker (or prior gitignore-manifest membership) to clean a retired path. The marker-presence guard conflated two distinct ownership questions:

| Scope | Question | Mechanism |
|---|---|---|
| Content-INSIDE-file preservation | What content inside the file to preserve across sync? | `ManagedRegion` markers — still load-bearing for active guideline files |
| Whether-file-exists | Should the file exist at all? | Path-ownership — this method's scope |

The marker is right for the former. Wrong for the latter. The reframe separates them.

#### Concrete consumer-side failure mode the marker-guard hid

Pre-0.8.2 wholesale-sync output at `.github/copilot-instructions.md` has no markers (ManagedRegion was introduced in 0.8.2). The 0.9.1-0.9.5 marker-guard skipped cleanup on those files indefinitely, leaving dead-weight boost-emitted content tracked across consumer repos. One adoption-cycle report (consumer with continuous git history pre-dating boost-core 0.8.2) confirmed the file silently survived four consecutive 0.9.x patches.

0.9.6 cleans it on the next sync, unconditionally.

### Design contract

**Path-ownership taxonomy:**

1. `.ai/` — user-authored sources, untouched by sync.
2. `vendor/<pkg>/resources/boost/...` — vendor-published, synced read-only into target dirs.
3. **Everything else under agent-target paths** (`AGENTS.md`, `CLAUDE.md`, `.github/copilot-instructions.md`, `.agents/`, `.claude/`, etc.) — boost-core's emit surface, managed end-to-end.

Operator influence on category 3 runs through:

- `.ai/` sources
- Allowlisted vendor packages (`->withAllowedVendors([...])`)
- Remote skills (`->withRemoteSkills([...])`)
- `boost.php` config (including `->withConventions([...])` for Project Conventions)

**Not** through hand-editing emission targets directly. If you intentionally authored content at a category-3 path outside boost-core's emission flow, recover from git history before the next sync.

### Trigger conditions named explicitly

The cleanup diagnostic names all three trigger conditions:

1. The agent owning the path is in the active agent set (e.g., `.github/*` cleanup requires `Agent::COPILOT`).
2. The path is in the retired-paths registry (hardcoded list of paths boost-core has emitted to in past versions but no longer maintains).
3. The path exists on disk.

All three must hold. Meeting all three means the file is unambiguously boost-emitted historical output. Delete unconditionally.

### Retired-paths registry

Current entries:

- `.github/copilot-instructions.md` — retired in 0.9.0 (Copilot reads root `AGENTS.md` per [GitHub Changelog 2025-08-28](https://github.blog/changelog/2025-08-28-copilot-coding-agent-now-supports-agents-md-custom-instructions/))
- `.github/skills` — retired in 0.9.1 (Copilot reads `.agents/skills/` via shared-pool routing)

Adding a path to the registry is a conscious decision recorded in source. The registry IS the audit surface for "what cleanup contract does sync enforce."

### Internal changes

- `SyncEngine::cleanupStalePaths()` rewritten: marker-presence check + prior-gitignore-manifest check both removed. Single foreach over the retired-paths registry, gated on Copilot active.
- `stripManagedRegion()` helper removed (unused after the reframe).
- Diagnostic copy updated to name the path-ownership contract + the trigger conditions + point operators at git-history recovery if intentional.
- Tests updated across three cohort cases: pre-0.8.2 unmarkered content + 0.8.2+ ManagedRegion content + mixed-ownership content. All assert unconditional cleanup behavior.
- PHPStan baseline complexity dropped 215 → 197 (helper removal).

### Compatibility

Compatible with `boost-core ^0.9`. Consumers on `0.9.0` through `0.9.5` upgrade by `composer update sandermuller/boost-core` — no `boost.php` or config changes required. The first sync after upgrade may auto-delete legacy content at retired paths if present; commit the resulting `git rm` as a separate cleanup commit.

**For consumers who deliberately authored content at category-3 paths outside boost-core's emission flow** (the design-intent-violation case), preserve via git history before running the first 0.9.6 sync. The path-ownership contract treats such content as unsupported.

### Cycle context

The reframe surfaced via cross-peer design challenge during the cycle-end evaluation pass. Two consumer-adjacent peers independently converged on the same critique from different angles — concrete failure-mode observation and conceptual ownership-scope analysis. Convergent-independent peer-read as the validation signal that the marker-guard's design intent was wrong-scope.

The codified rules from earlier in the 0.9.x cycle paid back during this reframe:

- The codify-vs-record distinction applied directly — the reframe was a load-bearing codification.
- The auto-X-must-name-trigger rule shaped the new diagnostic copy.
- The load-bearing-only floor-bump discipline applies: this is a load-bearing patch (closes a real silent-data-retention failure mode); downstream packages may floor-bump to `^0.9.6` for the cleanup-correctness gain.

The 0.9.x line surfaces an observation worth recording per the meta-rule: this cycle was the first where strategy-doc beats demonstrably influenced decisions mid-application rather than being retrofitted after. First observation; queueing.

### Tag-cut canonical command shape

Per the agent-handoff-command-shape discipline codified across the cross-package cycle:

```bash
gh release create 0.9.6 \
    --target main \
    --title "v0.9.6" \
    -F internal/release-notes-0.9.6.md






















```
**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.5...0.9.6

## [0.9.5](https://github.com/sandermuller/boost-core/compare/0.9.4...0.9.5) - 2026-05-28

Doc-tier patch. Two improvements addressed across the 0.9.x cycle's verbose-feedback exchange:

1. **`SyncEngine` render-fail diagnostic now names the specific marker pair** (`<!-- boost-core:guidelines:start -->` and `<!-- boost-core:guidelines:end -->`) instead of the abstract phrase "managed-region content preserved at prior state." Operators can grep the marker strings directly when investigating; the abstract phrasing forced a second lookup to find which markers it meant. Three-second cost per sentence, closes the abstract-vs-concrete gap for cold readers.
   
2. **`RELEASING.md` rewritten** to remove the obsolete `1.0.0-alpha.X` workflow content (long superseded by the `pre-release` skill), add the **load-bearing-only floor-bump discipline rule** (the rule generalizes across engine→catalog, catalog→family-package, family-package→consumer-app), cross-reference the `pre-release` skill as the canonical workflow, and add a family-release-sequencing protocol summary with cross-reference to the boost-skills strategy doc as the canonical location for the full protocol.
   

### Fixed

#### Marker-name tightening in render-fail diagnostic

`SyncEngine::cleanupStalePaths()` render-fail diagnostic was:

> "Guideline render failed; managed-region content preserved at prior state. Run `vendor/bin/boost sync` again after resolving the render failure. Source: %s"

Now:

> "Guideline render failed; content between `<!-- boost-core:guidelines:start -->` and `<!-- boost-core:guidelines:end -->` preserved at prior state. Run `vendor/bin/boost sync` again after resolving the render failure. Source: %s"

The wording-revert-as-regression-test pattern is applied (per CONTRIBUTING.md): the integration test now asserts the new marker-named copy AND fails on the old abstract phrasing. Future PRs that revert the wording fail the lock test, preserving the marker-grounded form.

#### `RELEASING.md` modernization

The previous version (recorded 2026-05-18 per its in-doc dating) carried the alpha-workflow first-publish content from when the family was still on `1.0.0-alpha.X` tags — long superseded by the Packagist-published 0.x stream. Replaced with the canonical content actually used today:

- Version-stream policy reflecting current state (0.9.x current, 0.10.0 noise-pass queued, 1.0.0 future).
- Load-bearing-only floor-bump discipline rule (the rule that emerged from the 0.9.x cycle's `^0.9.3` vs `^0.9.4` floor-pin decision).
- Family-release-sequencing protocol summary (full protocol lives in the boost-skills strategy doc).
- Cross-references to the `pre-release` skill (canonical workflow) and the strategy doc (canonical protocol home).

### Compatibility

Compatible with `boost-core ^0.9`. Consumers on `0.9.0` through `0.9.4` upgrade by `composer update sandermuller/boost-core` — no `boost.php` or config changes required. Per the load-bearing-only floor-pin rule introduced in this release's own `RELEASING.md` update, this is a polish-tier patch that downstream packages absorb via range constraint rather than floor-bumping their constraint to `^0.9.5`.

### Internal

- Single string substitution in `SyncEngine.php`; regression test updated in `EndToEndSyncTest.php` with both new-wording-present and old-wording-absent assertions.
- `RELEASING.md`: 211 lines → 156 lines (mostly removing obsolete alpha-workflow content; adding the floor-bump rule + cross-references).
- No PHPStan baseline drift, no schema changes, no public API surface changes.

### Cycle context

The marker-name tightening is the fourth instance of the abstract-vs-concrete wording-principles rule applied across the 0.9.x cycle. The rule (per the diagnostic-copy locked-version reference): any UPGRADING.md / release-notes / diagnostic prose that references "the managed region" or "boost-managed paths" should name the specific marker pair or path glob. Three-second cost per sentence; closes the abstract-vs-concrete gap for cold readers.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.4...0.9.5

## [0.9.4](https://github.com/sandermuller/boost-core/compare/0.9.3...0.9.4) - 2026-05-28

UX patch for diagnostic visibility on the bare-CLI sync surface. The 0.9.3 render-fail safety gate correctly preserves prior content + emits a "managed-region content preserved at prior state" reassurance to `SyncResult::diagnostics`, but boost-core's own `SyncCommand` rendered those diagnostics only on the success path. When the sync also carried top-level errors (e.g., the renderer error that triggered the safety gate in the first place), `report()` short-circuited on `$result->hasErrors()` BEFORE rendering diagnostics — exactly the moment operators most needed the reassurance.

### Fixed

#### Diagnostics now render BEFORE the error short-circuit

`SyncCommand::report()` flow reordered: `renderConventionsDiagnostics()` runs first, then error handling. The 0.9.3 render-fail reassurance now lands alongside the per-source error lines so operators see both signals together — error: "render failed for `<source>`" AND reassurance: "managed-region content preserved at prior state."

The bug surfaced during adoption dogfood on the 0.9.3 verification cycle: a proving consumer ran the bare-CLI repro path, hit the expected renderer errors, confirmed CLAUDE.md byte-preserved via md5, but reported "I don't see the new diagnostic." The mechanical safety contract was working; the operator-visibility surface was hiding the load-bearing reassurance text.

### Changed

#### Diagnostics section header

`SyncCommand` renames the section header from `Project Conventions` to `Diagnostics`. The 0.8.x-era header was accurate when conventions-schema was the only diagnostic source; as of 0.9.1+ the `SyncResult::diagnostics` list carries multiple kinds:

- Conventions warn/error (since 0.8.0)
- Clean-slate stale-removal info (since 0.9.1)
- Copilot-instructions strip info (since 0.9.1)
- Render-fail safety warnings (since 0.9.3)

The misleading header caused operators scanning for non-conventions content to scroll past the section, missing diagnostics that applied to them. Generic `Diagnostics` covers all four kinds correctly.

### Added

#### `CONTRIBUTING.md` patterns section

Codifies the **wording-revert-as-regression-test** pattern after three observed uses across distinct contexts (DiagnosticCopyLockTest, ConvertConventionsCommand `git rm --cached` lock, Copilot guideline-file strip diagnostic). Pattern: ship wording reverts with a test that asserts the new wording AND fails on the old wording.

Also codifies the **meta-rule for when to codify patterns** — wait for 2-3 occurrences across distinct contexts before promoting a pattern to documented convention. The wait prevents both premature codification (one-off becomes load-bearing rule) and under-codification (pattern reused N times never gets written down).

### Internal

Patch is two surgical edits to `src/Commands/SyncCommand.php` (~5 net lines) plus a CONTRIBUTING.md documentation addition. No PHPStan baseline drift, no schema changes, no public API surface changes beyond the operator-visible section header rename.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.3...0.9.4

## [0.9.3](https://github.com/sandermuller/boost-core/compare/0.9.2...0.9.3) - 2026-05-28

### Fixed

#### Render-fail-then-write data loss

`SyncEngine::fanOut` now skips the per-target guideline-file `PendingWrite` when `$guidelineRenderErrors` is non-empty. Prior managed-region body is preserved byte-for-byte. Skill + command writes still happen — those are per-file, so individual render failures don't poison sibling outputs. Guidelines are different: they all concatenate into one file via `ManagedRegion`, so a single failed render previously produced an incomplete body that replaced the prior complete one.

**Failure shape this closes:** any `SkillRenderer` (Blade, custom, vendor-provided) that throws during a sync run. The bug is generic to the renderer pipeline, not specific to any one renderer. The dogfood path that surfaced it required (a) `vendor/bin/boost sync` invoked outside the renderer's container, (b) a renderer with container-dependent state, (c) transient render failure — but the engine-level bug class is broader.

Each render failure emits a warning diagnostic naming the failed source file:

> "Guideline render failed; managed-region content preserved at prior state. Run `vendor/bin/boost sync` again after resolving the render failure. Source: guideline render failed (`hihaho.blade.php`, renderer `LaravelBoost\Renderers\BladeRenderer`): Call to undefined method Illuminate\Container\Container::path()"

The source name comes from `GuidelineLoader`'s existing error message format (file path + renderer FQCN + exception message) — operators see exactly which file + renderer failed without grepping logs.

#### Regression test asserts byte-for-byte preservation

`tests/Integration/EndToEndSyncTest.php` simulates a `SkillRenderer` that throws on `render()` and asserts:

1. Sync completes without errors propagating to the operator.
2. CLAUDE.md guideline body matches the prior-sync content byte-for-byte (not just non-empty).
3. Warning diagnostic surfaces in `SyncResult::diagnostics` with the failed source named.

The byte-for-byte assertion catches a subtle near-miss class where the preservation logic might emit a "close-but-not-identical" version (whitespace drift, ordering change, etc.). Equality on bytes is the strongest preservation contract.

### Internal

- `SyncEngine::fanOut()` signature gains a `$guidelineRenderErrors` parameter (default empty list for back-compat with non-engine callers that wouldn't be passing render-error context).
- `SyncEngine::sync()` collects per-error `Diagnostic::warning` records into `SyncResult::diagnostics` alongside the existing conventions + cleanup diagnostics.
- PHPStan class cognitive complexity baseline drift: SyncEngine `209 → 215`. The orchestrator continues to grow toward a decomposition cliff; that refactor is candidate work for a future release once the safety-net contracts stabilize.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.2...0.9.3

## [0.9.2](https://github.com/sandermuller/boost-core/compare/0.9.1...0.9.2) - 2026-05-28

### Fixed

`BoostConfig::$conventions` defaults to `[]` for back-compat with consumers who haven't adopted the 0.9.0 conventions-source-flip. PHP's empty array serializes to JSON `[]`, but the conventions schema declares `type: object`. `opis/json-schema` then rejected the data as `"data (array) must match the type: object"` for any consumer running `vendor/bin/boost validate` without a declared `withConventions` chain.

`ConventionsSchema::validate()` now casts the empty-array case to `stdClass` so the validator sees `{}` and validates cleanly. Non-empty associative arrays already serialize to JSON objects via `Helper::toJSON`, so the change is scoped to the empty case only.

**Design call: cast over skip.** The alternative was to skip validation entirely when `$hostValues === []`. The cast preserves the "vendor schema declares a required slot but operator hasn't filled it" surfacing path — schemas with required keys still surface "required key X missing" against the empty `{}`, which is the correct operator-facing signal. A skip-on-empty mode would have introduced silent-pass behavior for that case.

### Compatibility

Compatible with `boost-core ^0.9`. Consumers on `0.9.0` / `0.9.1` upgrade by `composer update sandermuller/boost-core` — no `boost.php` or config changes required. The fix is at the validator boundary; no behavioral change for consumers who already declare `withConventions([...])`.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.1...0.9.2

## [0.9.1](https://github.com/sandermuller/boost-core/compare/0.9.0...0.9.1) - 2026-05-28

Engine resilience patch. Three improvements anchored in real consumer dogfood:

1. **Conventions sync re-renders correctly on key removal.** 0.9.0's fail-closed conflict check was too strict — parseable-body divergence (including legitimate key removals from `boost.php`'s `->withConventions([...])`) was being treated as a two-source conflict and silently skipped, leaving `CLAUDE.md` stale. 0.9.1 relaxes parseable-body diffs from `error + skip` to `warning + proceed`. `boost.php` is canonical; `CLAUDE.md` is re-rendered. Unparseable bodies still error-and-skip — they signal broken hand-edits where preserving recovery context is worth the skip.
   
2. **Generic clean-slate sync model kills the stale-emission class.** Any file inside a boost-managed gitignored path BEFORE the sync but NOT rewritten this run is now auto-deleted. Catches vendor drops, allowlist changes, and target relocations without per-case cleanup logic. Guideline files (`CLAUDE.md`/`AGENTS.md`/`GEMINI.md`) are NOT touched — they use `ManagedRegion` and are operator-tracked. The pass is gated on sync error state: any error (fanOut, remote ingest, emitter) skips the clean-slate pass so transient fetch failures don't sweep cached recovery content.
   
3. **Copilot routes skills to the shared `.agents/skills/` pool.** Per [GitHub Docs: Adding agent skills](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/add-skills) (2025-12-18 Changelog: "GitHub Copilot now supports Agent Skills"), Copilot reads project skills from `.github/skills`, `.claude/skills`, OR `.agents/skills` interchangeably. `CopilotTarget` now joins the shared `.agents/skills/` producer pool alongside `CodexTarget`. Consolidates the emission surface — consumers with Copilot + Codex active no longer duplicate skill files across two directories. Legacy `.github/skills/` from 0.8.x cleanup happens automatically via the clean-slate pass.
   

### Changed

#### Conventions reconcile semantics

Parseable-but-divergent CLAUDE.md marker body → warning + proceed (was: error + skip). Operators editing `boost.php` see their changes flow to `CLAUDE.md` on the next sync. The warning preserves an operator-edit visibility signal without blocking the canonical flow:

> "Project Conventions: CLAUDE.md's marker body differs from boost.php's `->withConventions([...])`. boost.php is canonical; CLAUDE.md is being re-rendered to match. If you intentionally edited CLAUDE.md, that change is being overwritten — make the edit in boost.php's `->withConventions([...])` chain instead."

Unparseable bodies retain the error-skip behavior to preserve recovery context.

#### Copilot skill directory

`CopilotTarget::skillsDirectoryRelative()` returns `.agents/skills` (was `.github/skills`). Routes Copilot skill emission to the shared pool that `CodexTarget`, `CursorTarget`, `JunieTarget`, `KiroTarget`, `OpenCodeTarget`, and `AmpTarget` already publish to. Same shared-pool pattern that `AGENTS.md` uses for guideline files (0.9.0).

### Added

#### Sync clean-slate pass

`SyncEngine` now snapshots prior boost-managed paths from the `.gitignore` managed block BEFORE the new block is written, then post-sync deletes any file in the snapshot that wasn't rewritten this run. Reports drift via `WrittenFile` entries with `DELETED` / `WOULD_DELETE` actions — `--check` mode + `boost doctor` correctly surface the upcoming cleanup as `countWouldChange > 0` + `hasDrift() = true`.

**Safety contracts the design preserves:**

- Guideline files (`CLAUDE.md`/`AGENTS.md`/`GEMINI.md`) not in the managed gitignore block — unaffected.
- Error state (any fanOut / remote / emitter error) skips the clean-slate pass — preserves "transient fetch failure preserves prior content" behavior the remote-skill subsystem relies on.
- Symlinks at the pattern root are never followed or deleted — matches `FileWriter::anySegmentIsSymlink()` existing contract.
- `--check-only` mode reports `WOULD_DELETE` records, no actual deletion.

**Documented operator stance:** boost-managed gitignored paths are boost-owned end-to-end. Hand-authored content at those paths is design-intent-incorrect and will be swept by the clean-slate pass on the next sync. Operators who want persistent content in these locations should:

- Put it in `.ai/skills/` (host-source path) so boost-core renders it through the normal pipeline, OR
- Move it to a path outside the boost-managed gitignore block.

#### Stale `.github/copilot-instructions.md` cleanup

When Copilot is in the active agent set and `.github/copilot-instructions.md` exists with boost-core's `<!-- boost-core:guidelines:start -->` marker, sync strips ONLY the managed region (markers + body + explainer comment). Operator content OUTSIDE the markers (custom H1, intro prose, manually-added rules) is preserved verbatim — same round-trip safety contract `CLAUDE.md`/`AGENTS.md`/`GEMINI.md` get from `ManagedRegion`. If stripping leaves the file empty (no operator content was outside the markers), the file is deleted entirely.

**Upgrading from 0.8.x:** consumers who tracked `.github/copilot-instructions.md` from prior syncs will see it automatically stripped or removed on the next 0.9.1 sync. If your previous CLAUDE.md / AGENTS.md ended up with duplicate-content drift (pre-0.9.0 wholesale-write left a copy above the managed-region markers), inspect both files post-upgrade and trim any pre-0.9.0 generated content above `<!-- boost-core:guidelines:start -->` manually.

### Fixed

- `InjectedVendorMerger::mergeExtraRenderers()` already fixed in 0.9.0 (P1 from that release); 0.9.1 doesn't touch that path.
- Convention block sync skipped writes silently when CLAUDE.md's marker body diverged from `boost.php` in any way — addressed via the warn-and-proceed semantics above. Key removal from `withConventions([...])` was the surfacing scenario reported by adoption dogfood.

### Internal

- `SyncEngine::enumerateManagedFiles()` / `cleanupStaleManagedFiles()` / `removeEmptyParentDirs()` / `stripManagedRegion()` — internal helpers for the clean-slate model. Heavy lifting kept private to `SyncEngine` to avoid expanding the public API surface for a behavior the engine owns.
- `SyncEngine::cleanupStalePaths()` — narrowed to the marker-detection path for `.github/copilot-instructions.md` (preserves outside-marker bytes). The `.github/skills/` cleanup is now handled by the generic clean-slate pass via the prior-gitignore manifest.
- Class cognitive complexity baseline drift: SyncEngine `134 → 209` across the 0.9.1 changeset. Earned by the clean-slate machinery + the cleanup safety nets. Decomposing the orchestrator into helper classes is a candidate refactor for a future release; the current single-class shape stays readable through the safety-contract docblocks.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.9.0...0.9.1

## [0.9.0](https://github.com/sandermuller/boost-core/compare/0.8.3...0.9.0) - 2026-05-28

The Project Conventions edit surface moves from CLAUDE.md's marker-bounded YAML body to `boost.php`'s `->withConventions([...])` chain. `boost.php` becomes the source of truth; CLAUDE.md becomes a rendered audit trail.

### Why

0.8.x put operator-edited slot values inside CLAUDE.md's marker-bounded YAML block. Two-source ownership of one file made tracking semantics fragile (0.8.3 had to ship a `.gitignore` patch to preserve operator content across clones) and put the edit surface in a markup format alongside generated guideline content. The 0.8.3 release notes flagged a redesign as the 0.9.0 roadmap entry; this is that redesign.

In 0.9.0, operators declare slot values in `boost.php` — the same configuration file that already drives every other adoption decision (agents, vendors, tags, renderers). One canonical edit surface, type-checked PHP, IDE-completion-friendly, no markup parsing on the operator side.

### Authoring

```php
// boost.php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withConventions([
        'jira' => ['project_key' => 'HPB'],
        'github' => ['default_base_branch' => 'develop'],
        'branches' => [
            'patterns' => [
                ['pattern' => 'feature/HPB-XXXX', 'base' => 'develop'],
            ],
        ],
    ]);




























```
`boost sync` renders the values into CLAUDE.md's marker-bounded region (audit trail) and runs schema validation. `boost validate` / `boost slots` / `boost doctor --check-conventions` source from `boost.php`'s declared values.

### Added

- `BoostConfigBuilder::withConventions([...])` — the new declaration surface, validated against allowlisted vendors' schemas at sync + validate time.
- `vendor/bin/boost convert-conventions` — one-shot migration command. Extracts an existing CLAUDE.md marker-bounded YAML body into `boost.php`'s `->withConventions([...])` chain. `--dry-run` previews the rewrite; `--keep-block` leaves the CLAUDE.md body intact for manual reconciliation.
- Fail-closed sync contract — when `boost.php` and CLAUDE.md's marker body declare different values (or the CLAUDE.md body fails to parse), `boost sync` refuses to overwrite and surfaces an explicit reconcile path. Prevents silent destruction of operator content during partial migrations.

### Changed

- `boost sync` reads Project Conventions from `BoostConfig::$conventions` (sourced from `boost.php`), not the CLAUDE.md marker body. The marker body becomes a rendered output.
- `boost validate` / `boost slots` / `boost doctor --check-conventions` validate the `boost.php` declaration, not the YAML body.
- `CopilotTarget` joins the shared-AGENTS.md producer pool (Cursor / Codex / Junie / Kiro / OpenCode / Amp). 0.8.x emitted `.github/copilot-instructions.md` separately; that file is no longer written. Copilot reads root `AGENTS.md` per [GitHub Changelog 2025-08-28](https://github.blog/changelog/2025-08-28-copilot-coding-agent-now-supports-agents-md-custom-instructions/), expanded across cloud-agent / CLI / JetBrains surfaces through 2026. Upgraders with `.github/copilot-instructions.md` already tracked from a previous sync will see the file go untouched on future syncs — it is no longer regenerated, leaving stale-but-intact content. Cleanup is optional: `git rm .github/copilot-instructions.md` removes the now-frozen file. The boost-managed `.gitignore` block was never tracking it, so no gitignore changes are required.

### Migration

```bash
composer update sandermuller/boost-core
vendor/bin/boost convert-conventions --dry-run    # preview boost.php rewrite
vendor/bin/boost convert-conventions              # apply
vendor/bin/boost sync                             # re-render CLAUDE.md from boost.php
vendor/bin/boost validate                         # confirm 0 errors
git add boost.php CLAUDE.md
git commit -m "Migrate Project Conventions to boost.php"




























```
The migration is idempotent on the `boost.php` side (re-running `convert-conventions` is a no-op once values are declared). CLAUDE.md stays tracked — operator-authored content outside the conventions markers (custom H1, intro prose) is preserved across sync via the same marker-bounded round-trip safety shipped in 0.8.2.

`boost-core ^0.9` for any consumer adopting the new edit surface. The `BoostConfig::$conventions` field is positional-last with a default of `[]`, so consumers that don't declare conventions inherit empty defaults at construction — back-compat with positional `new BoostConfig(...)` callers.

### Fixed

The pre-tag eval pass caught a wrapper-path regression in the rebuild-and-drop class: `InjectedVendorMerger::mergeExtraRenderers()` rebuilt `BoostConfig` for the renderer-injection path but did not carry `$conventions` through. Wrapper layers using `extraSkillRenderers` (Laravel-side `project-boost-laravel`, others) would have silently flipped `BoostConfig::$conventions` to `[]` on every wrapper-mediated sync. Regression test in `BoostConfigBuilderTest.php` asserts the merger preserves conventions across renderer injection.

Two narrower fixes from the same pass:

- `ConventionsBlockEmitter::renderFromValues()` returned null when CLAUDE.md didn't yet exist but `boost.php` declared conventions — fresh repos with conventions-only-no-guidelines had validated boost.php values that never reached the rendered file. Now bootstraps `## Project Conventions` + rendered region.
- `BoostConfigWriter::writeConventions()` rejected the bare `return BoostConfig::configure();` shape. `convert-conventions` would have failed for any project that had not yet added another method chain (e.g. `->withAgents([...])`). Now wraps the bare static call with a synthetic `->withConventions([...])` method-call AST node, matching the pattern `update()` uses.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.8.3...0.9.0

## [0.8.3](https://github.com/sandermuller/boost-core/compare/0.8.2...0.8.3) - 2026-05-28

### Fixed

#### Guideline file no longer gitignored

The 0.7.x contract was "CLAUDE.md is 100% boost-generated, don't track." 0.8.x added operator-editable content (the Project Conventions YAML block) inside the same file but never updated the gitignore stance — making the file both operator-owned-in-part AND gitignored. Operators who filled the conventions block saw their slot values vanish on fresh clones or different machines: a freshly-cloned project's first `boost sync` regenerated CLAUDE.md from scratch, with the scaffold body only.

`AgentTarget::gitignorePatterns()` no longer includes `guidelinesFileRelative()`. The managed `.gitignore` block now lists only the directories boost-core fully owns (`.claude/skills/`, `.claude/commands/`, `.cursor/skills/`, etc.) — the per-agent guideline files (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`) drop out.

Migration is automatic: the next `boost sync` after upgrade rewrites the managed `.gitignore` block without the guideline file entries. Operators add the file to version control:

```bash
composer update sandermuller/boost-core
vendor/bin/boost sync          # rewrites .gitignore's managed block
git add CLAUDE.md              # or AGENTS.md / GEMINI.md per active agents
git commit





























```
Filled Project Conventions content is now tracked. Round-trip-safe across clones, machines, and CI environments.

### Trade-off (documented)

Tracking the guideline file means generated guideline content (boost-core's per-skill / per-guideline body concatenation) appears in PR diffs alongside operator-edited conventions content. Mitigations:

- Land skill changes in a separate commit from conventions edits.
- Use `git diff -- ':!CLAUDE.md'` aliases for reviewer ergonomics where the noise matters.
- Accept the noise as the cost of single-file simplicity.

A cleaner separation — operator content in a tracked `.ai/project-conventions.yaml`; rendered output in untracked CLAUDE.md — is on the 0.9.0 roadmap. The redesign deserves the same spec rigor conventions-schema itself got: proving-consumer signal, interview between schema-side / engine-side / consumer-side stakeholders, soak time. 0.8.3 ships the immediate-unblock patch; 0.9.0 (or later) ships the redesign.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.8.2...0.8.3

## [0.8.2](https://github.com/sandermuller/boost-core/compare/0.8.1...0.8.2) - 2026-05-28

### Fixed

#### `boost sync` no longer wipes the Project Conventions YAML block

**Root cause.** `AgentTarget::plan()` emitted the agent guideline file (CLAUDE.md for Claude Code, AGENTS.md for Cursor / Codex / OpenCode / Amp, GEMINI.md for Gemini, etc.) as a `PendingWrite` whose `content` was the wholesale concatenation of resolved guideline bodies via `formatGuidelinesContent()`. `FileWriter` then wrote that as the FULL file content. Anything OUTSIDE the guideline content — operator H1, prose, the marker-bounded Project Conventions block — was destroyed every sync.

The 0.8.0 conventions-schema design relied on the operator being able to add a `## Project Conventions` H2 to CLAUDE.md and fill values inside marker-bounded YAML, but the engine's existing guideline-write path was never converted to a marker-bounded write. Internal self-review, codex review, and verification all missed the gap because none exercised the full sync-fill-resync round-trip — they checked the conventions block in isolation (parse / scaffold / validate) but not the engine's sync path that also writes the same file.

**Fix.** The agent guideline file is now marker-bounded. Boost-core writes its guideline content between `<!-- boost-core:guidelines:start -->` / `<!-- boost-core:guidelines:end -->` markers; everything OUTSIDE the markers is operator-owned and preserved across syncs. The marker pair sits in the same `ManagedRegion` shape that backs `.gitignore` and the Project Conventions block — same write-merge-extract semantics, applied to the guideline region.

**Implementation.**

- `PendingWrite` gains an optional `managedRegion: ?ManagedRegion` field.
- `FileWriter::resolveFinalContent()` applies `ManagedRegion::render()` when the field is set; preserves content outside the markers and replaces only between them. Otherwise the write is wholesale (back-compat preserved for every other PendingWrite caller).
- `AgentTarget::plan()` attaches `guidelinesManagedRegion()` to the guideline-file PendingWrite.
- Round-trip regression test added (`tests/Integration/EndToEndSyncTest.php`): sync, fill operator section after the guideline content, sync again, assert the operator content + the conventions markers + the new guideline markers all survive.

### Migration

**First 0.8.2 sync emits a one-time warning** when an existing `CLAUDE.md` has Project Conventions markers but no guideline markers — the upgrade path from 0.8.0 / 0.8.1 / pre-0.8 conventions adopters. The warning prints once after the sync's primary output:

> Migration to 0.8.2 guideline managed-region: existing CLAUDE.md guidelines content is now wrapped in `<!-- boost-core:guidelines:start -->` markers. If you see duplicate guideline content above your Project Conventions section after this sync, it is pre-fix legacy and safe to delete manually. If your Project Conventions YAML was previously wiped by sync, re-fill it now — subsequent syncs will preserve operator-edited values.

Actions for consumers who hit the wipe:

1. `composer update sandermuller/boost-core` — pull 0.8.2.
2. `vendor/bin/boost sync` (or your wrapper's sync command).
3. Re-fill the YAML between the `<!-- boost-core:conventions:start -->` markers with your real slot values. Subsequent syncs will preserve everything inside operator-owned regions.
4. If you see duplicate guideline content above your Project Conventions H2 (an older, marker-less copy from pre-0.8.2 sync), delete it manually — one-time cleanup.
5. `vendor/bin/boost slots` to confirm slot fill-state, `vendor/bin/boost validate` to confirm schema-valid.

Subsequent syncs are round-trip safe — operator content outside the guideline markers is preserved unconditionally.

### Audit-lesson surfaced

For future managed regions inside shared files, the test matrix must include a full-sync round-trip that writes the region AND every sibling region in the same file, then asserts operator-authored sentinel content survives. Isolated parse / scaffold / validate tests are necessary but not sufficient. The new `EndToEndSyncTest` round-trip case codifies the rule for the guidelines + conventions file pair; the same pattern applies to any future managed region (the deferred `.vscode/settings.json` `yaml.schemas` block in particular).

### Upgrade

```bash
composer update sandermuller/boost-core






























```
Any consumer using the conventions-schema slot fill-in (boost-skills 1.7.0-rc1 + downstream) MUST be on 0.8.2 or later. Consumers not using conventions schema are unaffected — guideline files written by 0.8.x without the markered region were structurally identical to the pre-0.8.x format, and 0.8.2 transparently wraps the content on first sync.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.8.1...0.8.2

## [0.8.1](https://github.com/sandermuller/boost-core/compare/0.8.0...0.8.1) - 2026-05-27

### Fixed

#### `boost install` scaffold no longer seeds `->withDisabledEmitters([])`

`boost install` was emitting `->withDisabledEmitters([])` as an active line in the scaffolded `boost.php`. The empty-array form is a no-op — boost-core treats absent and empty-array identically — but every new consumer inherited the line, normalizing method-existence-as-documentation into the visible API surface of every downstream `boost.php`.

Family-wide audit found the same no-op in 4 of 4 audited consumer `boost.php` files. Fixing once at the scaffold template eliminates the drift at source; the inline `// ->withDisabledEmitters([SomeFqcn::class])` comment continues to teach the method exists without seeding new configs with a no-op chain.

Existing `boost.php` files keeping the empty-array form continue to work — `BoostConfigWriter`'s round-trip handling is unchanged. The fix only affects what `boost install` writes on a fresh scaffold.

### Changed

#### `README.md` "What you get" table

The agent-count comparison row (`laravel/boost: 4 / boost-core: 9 (+5)`) was stale and the delta had inverted: `laravel/boost` currently ships 10 agents on upstream `main` (added Antigravity since the row was authored), while `boost-core` still ships 9. Agent count is no longer a durable differentiation axis — competitor adding one agent flips the row.

Removed the Agents row. Added a Conventions schema row to surface the 0.8.0 capability that wasn't yet in the table when 0.8.0 shipped. The durable differentiation rows (framework-agnostic scope, `withTags()`, `withRemoteSkills()`, explicit `withAllowedVendors()`, user-scope sync, `boost where` origin tracing, `boost doctor`, `.ai/commands/` argument transpilation, conventions schema) all remain.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.8.0...0.8.1

## [0.8.0](https://github.com/sandermuller/boost-core/compare/0.7.6...0.8.0) - 2026-05-27

### TL;DR

- **Author once, fill per project.** Vendor ships a JSONSchema; consumer fills a single YAML block; the vendor skill reads it. No more 11-of-13 shadows just to inject project context.
- **Three new commands + one extension.** `boost validate`, `boost slots`, `boost paths`, and `boost doctor --check-conventions`. All opt-in surfaces — sync continues to work unchanged for projects without any vendor schemas.
- **`SyncResult::diagnostics` channel.** New lenient surface alongside the existing `errors` field. Conventions diagnostics never affect `boost sync` / `boost where` exit code; they render after the primary output for visibility.
- **No upgrade work required from 0.7.6** — additive surface. Existing projects without an allowlisted vendor declaring a `conventions-schema.json` continue to work unchanged.

### Added

#### Project Conventions block in `CLAUDE.md`

`boost sync` auto-scaffolds when any allowlisted vendor ships a `resources/boost/conventions-schema.json` and the consumer's `CLAUDE.md` lacks the block:

```markdown
## Project Conventions

<!-- Managed by boost-core. Edit the YAML between the markers; do not remove or move the markers. -->
<!-- boost-core:conventions:start -->
\`\`\`yaml
schema-version: 1
jira:
  project_key: HPB
github:
  default_base_branch: develop
\`\`\`
<!-- boost-core:conventions:end -->
































```
Operator owns the H2, the explainer comment, and the YAML body. boost-core never overwrites values — it only reports diagnostics. The `schema-version` field defaults to `1` if omitted; the scaffold seeds the highest `min(metadata.schema-required)` across allowlisted vendors so newer-schema vendors apply on first run.

#### New CLI commands

```bash
boost validate [--strict] [--json]      # validate Project Conventions against vendor schemas
boost slots [--vendor=X] [--missing] [--filled] [--json]   # list slots, fill state, declaring vendor
boost paths [--managed] [--json]        # list path globs boost-core manages
boost doctor --check-conventions        # opt-in conventions diagnostics in doctor output
































```
- `boost validate --strict` exits non-zero (1) on any error-level diagnostic. Default exit is always 0 (lenient).
- `boost slots --missing` and `--filled` are mutually exclusive — combo exits 2 (CLI usage error).
- `boost paths --managed` lists the agent-managed glob set; vendor skills can shell out to it to define semantics like "since the most recent code change to a file NOT in this list."
- `boost doctor --check-conventions` extends `boost doctor` with missing-required slot reports, unknown-slot warnings, schema-version mismatch notes, and file-existence checks for path-typed slots (`format: path`).

#### `SyncResult::diagnostics` channel (NEW)

```php
final readonly class SyncResult
{
    public function __construct(
        public array $writes,
        public array $emitters,
        public array $errors,       // legacy fatal-failure channel (preserved unchanged)
        public bool $check,
        public int $tagFilteredSkillsCount = 0,
        public array $hostShadows = [],
        public array $diagnostics = [],   // NEW: list<Diagnostic>
    ) {}
}
































```
Conventions diagnostics (error / warning / info) route through `SyncResult::diagnostics` and never affect `hasErrors()`. `SyncCommand` and `WhereCommand` render diagnostics after their primary output so error-level lines stay visible despite never triggering exit FAILURE. Backward-compatible — every 0.7.x reader of `SyncResult::errors` continues to work.

#### `Conventions::default()->managedPaths($config)` PHP API

```php
$paths = \SanderMuller\BoostCore\Conventions\Conventions::default()
    ->managedPaths($config);  // list<string> of glob patterns
































```
Programmatic equivalent of `boost paths --managed` for internal callers and future PHP consumers. The CLI form is the primary agent-reachable surface; the PHP API is the secondary stable contract.

#### Schema discovery surface

Vendors ship `resources/boost/conventions-schema.json` (JSONSchema draft 2020-12). Discovery is convention-driven — same pattern as `resources/boost/skills/` and `resources/boost/guidelines/`. The schema MUST declare `$id`, top-level `properties`, optional `metadata.schema-required` semver range. boost-core strips vendor-declared root `schema-version` properties before merging schemas, then injects a single synthetic `properties.schema-version: {type: integer, minimum: 1}` at the composed root — avoids cross-vendor `const: 1` vs `const: 2` contradictions that would make the composed schema unsatisfiable.

Same-typed slot collisions across vendors are silent (first-allowlisted wins). Different-typed slot collisions throw `SlotTypeMismatchException` at compose time, caught and surfaced as an error-level `Diagnostic` through `SyncResult::diagnostics`.

Malformed vendor `conventions-schema.json` (invalid JSON, JSONSchema parse failure) is lenient — the vendor is skipped from composition and a warning diagnostic surfaces through the same channel. Sync continues; other vendors' schemas remain validated.

### Changed

#### `bin/boost` autoload candidate order

The autoload candidate order in `bin/boost` is reversed: project top-level autoload (`__DIR__ . '/../../../autoload.php'`) now takes priority over boost-core's nested vendor autoload. The previous order broke path-repo dev installs — boost-core's nested autoload won, `InstalledVersions` returned paths into the nested vendor dir where vendor resources don't exist, and SchemaDiscovery silently skipped every vendor. Caught by the boost-skills peer's verification pass against the WIP branch.

#### `Sync/GitignoreManager` internals

Now delegates to the new `Conventions/ManagedRegion` utility for marker-bounded round-trip writing. Public API unchanged. The shared utility backs both `.gitignore`'s managed block and the new Project Conventions block, and is positioned for the deferred `.vscode/settings.json` `yaml.schemas` block (0.9.0).

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.6...0.8.0

## [0.7.6](https://github.com/sandermuller/boost-core/compare/0.7.5...0.7.6) - 2026-05-26

### TL;DR

- **One canonical syntax, seven distinct emits.** Source: `$ARGUMENTS` (unsplit), `$1`/`$2`/… (one-indexed positional), `$name` (named, optionally declared in frontmatter `arguments:`), `\$ARGUMENTS`/`\$N`/`\$name` for literal escapes. Transpiler converts to each agent's native shape on every sync.
- **Lossy modes surface, never silently corrupt.** Cursor + Amp have no placeholder support → emit verbatim + per-command warning. Junie requires all-named-and-required → auto-name positional `$N` to `$argN` + per-command warning recommending the operator declare them in frontmatter. Kiro doesn't document named placeholders → emit verbatim + per-command warning. All warnings route through `SyncResult::errors` (lenient — sync continues, operator sees the lines).
- **Originally resolved as PUNT, then reversed.** Initial agent-format research framed commands as a "fading primitive" — but a verification pass showed the broader market is split (4 of 9 agents actively investing in command features, only 3 deprecating), and skills' fuzzy description-matching is a real downside commands don't share. Adopted one-indexed canonical to sidestep the Claude-vs-OpenCode indexing clash via transpilation.
- **No upgrade work required from 0.7.5** — pure additive. Existing commands without argument placeholders continue to copy verbatim. The new behavior only activates for commands that USE placeholders.

### Added

#### Canonical placeholder syntax

```yaml
---
name: jira-triage
description: Triage and label an incoming Jira issue.
arguments:
  - issue
  - priority
---
Triage Jira issue $issue with priority $priority.
Full request: $ARGUMENTS

# Positional access (one-indexed):
Owner: $1, repo: $2

# Literal $ values via backslash escape:
Cost: \$100. Variable: \$ARGUMENTS.

































```
The frontmatter `arguments:` list is optional but recommended for named arguments — Junie uses it to satisfy its all-required-named-args contract; Claude/Copilot/OpenCode all benefit when the operator wants to declare names explicitly.

#### Per-agent transpilation table

| Agent       | `$ARGUMENTS`             | `$N` one-indexed           | `$name`                                          | Lossy?                       |
|-------------|--------------------------|----------------------------|--------------------------------------------------|------------------------------|
| Claude Code | `$ARGUMENTS`             | `$(N-1)` (zero-indexed)    | `$name`                                          | No                           |
| Cursor      | verbatim                 | verbatim                   | verbatim                                         | **Yes** — warns; no syntax   |
| Copilot     | `${input:args}`          | `${input:argN}`            | `${input:name}`                                  | No                           |
| Gemini      | doctor-only              | doctor-only                | doctor-only                                      | n/a — `boost doctor`         |
| Junie       | `$args`                  | `$argN` + warn             | `$name`                                          | Partial — auto-names positional |
| OpenCode    | `$ARGUMENTS`             | `$N` (native one-indexed)  | `$NAME` (uppercased)                             | No                           |
| Amp         | verbatim                 | verbatim                   | verbatim                                         | **Yes** — warns; no syntax   |
| Kiro        | `$ARGUMENTS`             | `${N}` (brace form)        | `$name` + warn                                   | Partial — named not native   |
| Codex       | doctor-only              | doctor-only                | doctor-only                                      | n/a — deprecated             |

Sample warning lines (lenient — sync continues):

```
[cursor] deploy: cursor has no placeholder syntax; canonical placeholders emitted verbatim.
[junie] deploy: Junie requires named+required args; positional `$1`, `$2` auto-named to `$arg1`, `$arg2` — declare them in the source frontmatter `arguments:` list so Junie can surface the required-fields prompt.
[kiro] deploy: Kiro does not document named placeholders; `$issue` emitted verbatim. Use `$ARGUMENTS` (unsplit) or `${1}`/`${2}` (positional) for cross-agent portability.

































```
#### `Command::argumentDeclarations`

Populated by `CommandLoader` from the optional frontmatter `arguments:` list. Drives:

- Junie's named-required emit contract.
- Future host-side validation ("body uses `$x` but `x` isn't declared" — deferred).
- Operator hint surface when the picker / doctor expand to cover commands (Phase 4 work).

#### Bundled `command-arguments` skill (rewritten)

`resources/boost/skills/command-arguments.md` was previously a "no transpilation, document only" doc (the PUNT-era shape from the same session). Rewritten to describe the canonical syntax, the per-agent transpilation table, the lossy modes + warnings, and a strategy guide ("when to use which shape"). Same trigger phrases — "how do command arguments work", "what placeholder syntax should I use", "why does $ARGUMENTS show literally on Cursor".

### Changed

#### `AgentTarget::planCommands()` return shape

```php
// Was:
public function planCommands(array $commands): array  // list<PendingWrite>

// Now:
public function planCommands(array $commands): array  // array{writes: list<PendingWrite>, warnings: list<string>}

































```
Internal-facing — `SyncEngine` is the only documented caller. The new `warnings` channel surfaces per-command transpile issues (e.g. "Cursor has no placeholder syntax; canonical placeholders emitted verbatim") that previously had nowhere to go.

If you wrap `AgentTarget::planCommands()` directly from a downstream package, dereference `['writes']` to get the previous `list<PendingWrite>` shape and `['warnings']` for the new channel.

#### `AgentTarget::transpileCommandBody()` new template method

```php
public function transpileCommandBody(Command $command): CommandTranspileResult

































```
Base implementation = "warn-and-verbatim" (used by Cursor + Amp). Five agents override with their native shapes: Claude, Copilot, Junie, OpenCode, Kiro. `CommandTranspileResult` carries `{content, warnings}`.

### Upgrade

```bash
composer update sandermuller/boost-core

































```
No `boost.php` change required. Existing commands without placeholders are unchanged. Commands using `$ARGUMENTS` / `$N` / `$name` will now transpile correctly per-agent on the next `boost sync`.

If you're a wrapper author calling `AgentTarget::planCommands()` directly: pull `['writes']` from the returned array.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.5...0.7.6

## [0.7.5](https://github.com/sandermuller/boost-core/compare/0.7.4...0.7.5) - 2026-05-26

### TL;DR

- **Three-step interactive install.** `vendor/bin/boost install` now walks **agents → vendors → tags** in sequence. The tag picker shows every tag declared by the selected vendors with an "unlocks N skill/guideline" hint per row; pre-checks tags already in `withTags(...)`; persists the operator's choice as variadic `withTags(Tag::CaseName, 'raw-string', …)` — Tag enum cases when matched, raw strings otherwise.
- **Renderer-aware discovery.** The picker reads the host's existing `withSkillRenderers([...])` configuration on re-install, so `.blade.php`-tagged vendor assets surface alongside plain Markdown. Custom renderer setups never silently hide their tags from the picker.
- **Hand-curated tags survive re-installs.** Tags declared in `boost.php` that no installed vendor publishes (org-internal tags, tags added ahead of vendor support) are preserved silently — the picker controls visible tags only, never strips invisible ones.
- **No upgrade work required from 0.7.4.** Pure additive UX. Hand-edited `boost.php` files continue to work unchanged; the picker only edits when you run `boost install`.

### Added

#### Interactive tag picker

```
 ┌ Which tags should boost-core enable? ─────────────────────────────────┐
 │ › ◼ php           (unlocks 4 skill/guideline)                         │
 │   ◻ laravel       (unlocks 2 skill/guideline)                         │
 │   ◼ jira          (unlocks 1 skill/guideline)                         │
 │   ◻ tailwindcss   (unlocks 1 skill/guideline)                         │
 └────────────────────────────────────────────────────────────────────────┘


































```
Runs after the vendor picker. Pre-checks any tag already declared in `withTags(...)` AND present in the discovered set; declared-but-undiscovered tags (e.g. org-internal tags, tags added ahead of vendor support) are preserved silently and merged back into the final selection.

Empty operator selection clears `withTags(...)` from the chain entirely. Skipping the picker (no vendors publish anything tagged) leaves any existing `withTags()` call untouched.

#### `AvailableTagsDiscovery`

```php
$counts = (new AvailableTagsDiscovery($packages))->discover($vendorNames, $renderers);
// ['github' => 1, 'jira' => 1, 'php' => 2, ...]


































```
Public helper that walks selected vendors via `VendorScanner`, loads their skills + guidelines through the caller's renderer dispatcher (auto-appends the implicit `PassthroughRenderer`), and returns a sorted `tag → unlock-count` map. Backs the picker; also usable directly by downstream tooling that wants the same data.

### Changed

#### `BoostConfigWriter::update()` accepts an optional `?array $tags`

```php
$writer->update(
    configPath: __DIR__ . '/boost.php',
    agents: $agents,
    allowedVendors: $vendors,
    disabledEmitters: [],
    tags: ['php', 'jira'],          // new — null/[] / non-empty trio of behaviors
);


































```
Three behaviors:

- **`null`** (default) — leave the existing `withTags(...)` call untouched. The install picker passes `null` when there's nothing to pick from, so the writer can't accidentally clear a hand-curated tag list.
- **`[]`** — remove `withTags(...)` from the chain entirely. The operator unchecked every visible option.
- **non-empty** — insert or replace `withTags(<args>)`. Each tag normalized (trim + lowercase + drop empty + dedupe) defensively, then emitted as `Tag::CaseName` when it matches a `Tag` enum case, raw string otherwise. Write/read round-trip through `BoostConfigBuilder::withTags()` is guaranteed.

Format-preservation behavior unchanged — the cloned-tree printing still reproduces every untouched node byte-for-byte from the original tokens; only the `withTags(...)` call is re-rendered.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.4...0.7.5

## [0.7.4](https://github.com/sandermuller/boost-core/compare/0.7.3...0.7.4) - 2026-05-26

### TL;DR

- **`boost where --diff=<skill>`** — pass a host-shadowed skill name and the command prints a unified diff between the host file and the vendor's upstream copy. Three outcomes: a real diff with `--- vendor:` / `+++ host:` headers when the two diverge, a "byte-identical — override earns nothing" hint when they match, or a friendly error when the named skill isn't shadowing anything. Built on `sebastian/diff` (promoted from transitive dev-dep to direct require).
- **Resolution pipeline aligned with `boost sync`.** The shadow lookup uses the same `SkillRendererDispatcher` and tag filter as a real sync, so `.blade.php` skills are discoverable and tag-filtered vendor copies are excluded — `--diff` diffs against what would actually ship, not what's on disk pre-filter.
- **No upgrade work required from 0.7.3.** The new flag is opt-in; existing invocations of `boost where` are unchanged.

### Added

#### `boost where --diff=<skill>`

```bash
vendor/bin/boost where --diff=deploy



































```
Resolves a single named host skill and the upstream vendor copy it shadows. Three exit paths:

**Unified diff** — the two files differ. Output names both paths and shows line-level changes (vendor lines are `-`, host lines are `+`):

```
Shadow diff — `deploy` (host) vs `acme/skills` (vendor)
--- vendor: /…/vendor/acme/skills/resources/boost/skills/deploy/SKILL.md
+++ host:   /…/.ai/skills/deploy/SKILL.md

@@ -3,3 +3,3 @@
 ---

-Run the deploy.
+Run the deploy. (with our extra step)



































```
**Byte-identical** — host and vendor copies match exactly. The command prints:

```
[OK] Host skill `deploy` is byte-identical to the `acme/skills` vendor copy.
     The override earns nothing — consider removing `<host path>` and shipping the vendor version.



































```
**Not a shadow** — the named skill doesn't exist host-side OR no allowlisted vendor publishes a skill of the same name. The command exits FAILURE with a friendly pointer at `boost where` for the resolved origin map.

Remote skill sources are NOT considered — the shadow concept applies only to scanned Composer vendors (`withAllowedVendors`). A skill from `withRemoteSkills(...)` cannot be shadowed in the boost-core resolution model.

#### `SyncEngine::resolveSkillShadowPaths(string $projectRoot, string $skillName): ?array`

New public inspection helper backing `--diff`. Returns `array{hostPath: string, vendorPath: string, vendor: string}` when both files exist and the host name matches an allowlisted vendor's skill, `null` otherwise. Same pipeline as `boost sync` — renderer dispatcher + tag filter applied before name-matching, so the result agrees with what a live sync would do.

### Changed

- **`sebastian/diff` promoted to direct require.** Previously available transitively through `phpunit/phpunit` in dev installs, now declared in `composer.json` `require` so end-user installs of boost-core ship with it. Adds ~50 KB to vendor; no further transitive deps.

### Upgrade

```bash
composer update sandermuller/boost-core



































```
No `boost.php` change required. The new flag is purely additive; existing `boost where` (no flag) renders the same three-category origin map.

If you script `boost where` output: `--diff=<name>` is a new opt-in mode that produces different output (unified diff or success/error message) — the no-flag invocation is unchanged.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.3...0.7.4

## [0.7.3](https://github.com/sandermuller/boost-core/compare/0.7.2...0.7.3) - 2026-05-26

### TL;DR

- **Three-category origin tracing.** A single `boost where` call now lists every resolved skill, guideline, and command grouped by origin, under a SKILLS / GUIDELINES / COMMANDS layout. Empty categories are silently omitted (a host-only project with no commands sees no COMMANDS header). Skills still surface inline `(shadows <vendor>)` annotations for host-vs-vendor overrides.
- **Per-category origin precision.** A scanned vendor that publishes only guidelines no longer mislabels a remote-only skill source as `vendor+remote`. The `remote` label is restricted to the SKILLS section since there is no remote-guideline or remote-command pipeline today.
- **Sync-time errors surface.** Errors that `boost sync --check` returns in `SyncResult::errors` (remote-source collisions, render failures, `would-fetch` advisories on cold cache) now print from `boost where` and exit non-zero. Previously they were silently dropped and the inspection rendered as if everything resolved cleanly.
- **No upgrade work required from 0.7.2.** Output layout and exit-code semantics changed but the command shape and CLI surface stay the same. Existing automation that greps `boost where` output for skill names keeps working.

### Added

#### `boost where` — three-category output

```
SKILLS
══════

host · .ai/skills/ (host) · 4 skill(s)
  • deploy
  • review
  • naming-conventions
  • write-blueprint (shadows acme/skills)

vendor · acme/skills · 3 skill(s)
  • lint
  • format
  • write-blueprint

remote · peterfox/agent-skills · 2 skill(s)
  • composer-upgrade
  • phpstan-developer

GUIDELINES
══════════

host · .ai/guidelines/ (host) · 1 guideline(s)
  • core

vendor · acme/skills · 2 guideline(s)
  • naming
  • testing

COMMANDS
════════

host · .ai/commands/ (host) · 1 command(s)
  • deploy




































```
Each category renders only when it has resolved items — empty sections vanish. The label scheme established in 0.7.2 carries across all three categories:

- **`host`** — `.ai/skills/`, `.ai/guidelines/`, `.ai/commands/`. On the SKILLS section, a `(shadows <vendor>)` annotation flags host items that override an allowlisted-vendor copy of the same name.
- **`vendor`** — a Composer-allowlisted vendor publishing via `resources/boost/skills/` or `resources/boost/guidelines/`.
- **`remote`** — `withRemoteSkills([RemoteSkillSource::...])` declaration. SKILLS only — guidelines and commands have no remote pipeline.
- **`vendor+remote`** — rare but legal: an `<owner>/<repo>` key participates in both a scanned vendor and a `withRemoteSkills(...)` entry (item names must still be unique upstream).

### Changed

#### `SyncEngine::resolveForInspection()`

New public inspection helper:

```php
$inspection = SyncEngine::default()->resolveForInspection($projectRoot);
// $inspection['skills']                       — list<Skill>
// $inspection['guidelines']                   — list<Guideline>
// $inspection['commands']                     — list<Command>
// $inspection['remoteSourceKeys']             — list<string> (skills-only)
// $inspection['scannedSkillVendorKeys']       — list<string>
// $inspection['scannedGuidelineVendorKeys']   — list<string>




































```
The 0.7.2 `SyncEngine::resolveSkillsForInspection()` is preserved as a thin back-compat wrapper that delegates to the new method and projects the result into the 0.7.2 shape (`{skills, remoteSourceKeys, scannedVendorKeys}` with `scannedVendorKeys` = the union of skill + guideline vendor sets). External callers wrapping the 0.7.2 method directly keep working without changes.

### Fixed

- **`boost where` surfaces sync-time errors instead of masking them.** Previously the command ran `sync(checkOnly: true)` and used the returned `SyncResult` for shadow annotations but never inspected `SyncResult::errors`. Cold-cache `withRemoteSkills` (the `would-fetch` advisory), remote-source key collisions, and host-vs-injected vendor collisions all return errors via that channel. They now print from `boost where` and the command exits FAILURE — matching what `boost sync --check` does.
- **Per-category provenance labels.** A package that publishes only guidelines could previously cause a SKILLS section to label a remote-only origin as `vendor+remote` (because the merged scanned-vendor set conflated skill + guideline publishers). Each category now consults its own scanned-vendor universe — SKILLS uses skill-publishing vendors + remote sources, GUIDELINES uses guideline-publishing vendors only, COMMANDS uses neither (Phase 1 host-only).

### Upgrade

```bash
composer update sandermuller/boost-core




































```
No `boost.php` change required. If you script `boost where` output: the per-skill bullet lines are unchanged (`  • <name>` with optional `(shadows <vendor>)` suffix). What changed is the surrounding section headers — SKILLS / GUIDELINES / COMMANDS now bracket the per-origin groups.

If you call `SyncEngine::resolveSkillsForInspection()` directly from a wrapper package: the 0.7.2 return shape is preserved. The new `resolveForInspection()` is the broader alternative when you want all three categories in one call.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.2...0.7.3

## [0.7.2](https://github.com/sandermuller/boost-core/compare/0.7.1...0.7.2) - 2026-05-26

Patch release. One new diagnostic (`boost doctor --check-versions`) and a precision fix in `boost where`'s origin labels. All additive — projects on 0.7.1 can upgrade without config changes; the new flag is opt-in and the label rendering only sharpens existing output.

### TL;DR

- **`boost doctor --check-versions`** — opt-in Packagist lookup that flags boost-* family packages installed from a Composer `path` repo when the `repositories[]` entry has outlived its purpose and shadows a newer published version. Closes a sharp foot-gun reported during the 0.7.0 stable migration where a stale path repo locked a consumer to a pre-stable SHA that lacked a public API the new caller invoked, fataling mid-sync. Routine `boost doctor` stays fully offline (CI-safe) — the lookup only runs behind the explicit flag, and a failed lookup degrades to "could not verify" rather than aborting.
- **`boost where` origin labels are now precise.** Previously a `<vendor>/<package>` group was tagged ambiguously as `vendor or remote` because both pipelines populate the same `sourceVendor` field. Each group now renders as `vendor` (Composer-scanned), `remote` (declared via `withRemoteSkills(...)`), or `vendor+remote` (the legal overlap where one `<owner>/<repo>` key participates in both). The operator stops grepping `boost.php` to disambiguate.
- **No upgrade work required from 0.7.1.** Both changes are additive; existing projects without `withRemoteSkills(...)` or a path-repo'd family package see no behavioral change in routine `boost doctor` / `boost where` invocations.

### Added

#### `boost doctor --check-versions`

```bash
vendor/bin/boost doctor --check-versions




































```
When the flag is set, doctor enumerates installed boost-* family packages, identifies the ones whose install path is OUTSIDE the project's `vendor/` (the Composer `path` repo signature, including the `symlink: true` default), and compares each against the latest stable version Packagist publishes.

```
Path-repo version check
-----------------------

 ------------------------- ----------------------- ------------------------- -------------------
  Package                   Installed (path repo)   Packagist latest stable
 ------------------------- ----------------------- ------------------------- -------------------
  sandermuller/boost-core   dev-main                0.7.2                     ⚠ Packagist newer
 ------------------------- ----------------------- ------------------------- -------------------

Path repos silently override Packagist resolution for matching constraints.
Remove unused `repositories[]` entries from composer.json + re-run `composer update` to pull from Packagist.




































```
One HTTP call per shadowed package against `repo.packagist.org`. Failed lookups (timeout, 404, malformed response) surface as `lookup failed` per row and never fatal. The check is fully read-only and gated behind the flag, so `boost doctor` without the flag remains network-free.

The package-name URL segment is rawurl-encoded before the request, and the version parser accepts both `version_normalized` (Composer's canonical form, e.g. `0.7.1.0`) and the human-readable `version` field (including `v`-prefixed tags). Prerelease versions (`-rc`, `-beta`, `-alpha`, `-dev`) are skipped — only stable triples count as "latest."

#### `Sync\InstalledPackages` exposed for downstream introspection

The injection seam on `DoctorCommand::__construct(injectedPackages: ...)` lets wrappers and tests drive the `--check-versions` flow with a synthetic install set instead of the live Composer runtime. The other diagnostic methods continue reading from `InstalledPackages::fromComposer()` directly — narrow scope by design.

### Changed

#### `boost where` origin labels — `vendor` / `remote` / `vendor+remote`

A `<vendor>/<package>` key can legally participate in both pipelines (a scanned Composer vendor publishing skills under `resources/boost/skills/` AND a `withRemoteSkills(...)` entry pointing at the same GitHub repo), as long as their skill names don't collide. Previously the single-flag classification mislabeled the merged group as `remote`. Now:

- **`host`** — `.ai/skills/` host-authored.
- **`vendor`** — scanned Composer vendor on `withAllowedVendors([...])`.
- **`remote`** — `withRemoteSkills([RemoteSkillSource::...])` declaration.
- **`vendor+remote`** — `<owner>/<repo>` key in both sets; rare but legal.

The shape of the inspection helper changed accordingly:

```php
// Was (0.7.1):
$skills = $engine->resolveSkillsForInspection($projectRoot); // list<Skill>

// Is (0.7.2):
$inspection = $engine->resolveSkillsForInspection($projectRoot);
// $inspection['skills']             — list<Skill>
// $inspection['remoteSourceKeys']   — list<string>
// $inspection['scannedVendorKeys']  — list<string>




































```
Internal-facing inspection API — no documented public consumers besides `WhereCommand` itself. External callers wrapping the method directly would need to dereference `['skills']`. Surfaced in the changelog under Changed (not Fixed) for that reason.

### Upgrade

```bash
composer update sandermuller/boost-core




































```
No `boost.php` change required.

If you're a wrapper author who calls `SyncEngine::resolveSkillsForInspection()` directly: pull `['skills']` from the returned array. Everyone else: no action.

## [0.7.1](https://github.com/sandermuller/boost-core/compare/0.7.0...0.7.1) - 2026-05-25

### TL;DR

- **Kiro joins the command fan-out as the seventh emit target.** A `.ai/commands/<name>.md` source now lands at `.kiro/skills/<name>/SKILL.md` — Kiro's slash-command surface IS its committed skills directory, so `/<name>` becomes invocable through the existing skill discovery. No new directory, no TOML, no per-agent config knob.
- **`boost doctor` calls out Codex and Gemini explicitly.** When `.ai/commands/` contains `.md` files (recursive — `sub/foo.md` counts) AND Codex or Gemini is in `withAgents()`, doctor prints a `Command-emit limitations` section pointing operators at the manual authoring path (`~/.codex/prompts/` for Codex; `.gemini/commands/<name>.toml` for Gemini). Previously both were silently skipped — operators only learned the commands weren't being written by manually checking each agent dir.
- **No upgrade work required from 0.7.0.** All additive; existing projects that don't populate `.ai/commands/` see no behavioral change.

### Added

#### Kiro command emit — `.kiro/skills/<name>/SKILL.md`

`KiroTarget::planCommands()` overrides the base implementation to emit each command as a skill-shaped file under Kiro's committed skills directory. Kiro treats anything committed under `.kiro/skills/<name>/` as both a skill AND a slash-command, so a single emit reaches both invocation paths.

```bash
# author once
.ai/commands/deploy.md

# fans out to (excerpt — full agent set unchanged from 0.7.0)
.claude/commands/deploy.md
.cursor/commands/deploy.md
.kiro/skills/deploy/SKILL.md   # ← new in 0.7.1





































```
`commandsDirectoryRelative()` stays `null` for Kiro so the managed `.gitignore` block, directory tooling, and gitignore-pattern reporters don't double-count `.kiro/commands/` (a directory Kiro doesn't use). The skill directory `.kiro/skills/` is already covered by the existing gitignore pattern.

The render path goes through `AgentTarget::formatCommandContent()`, identical to how skill emits go through `formatSkillContent()` — so subclass overrides and future render hooks apply symmetrically across both surfaces.

#### `boost doctor` — command-emit limitations section

```
Command-emit limitations
• Codex: prompts are deprecated and personal-only (`~/.codex/prompts/`). boost-core does not write
  there. To use these commands in Codex, copy your `.ai/commands/**/*.md` files into
  `~/.codex/prompts/` manually.
• Gemini: command files use TOML; boost-core does not generate them. Author Gemini commands
  directly in `.gemini/commands/<name>.toml` or use a skill instead.





































```
The section only renders when both conditions are met:

1. The configured `commandsPath` exists AND contains at least one `*.md` file (recursive scan via Finder — same shape `CommandLoader` uses).
2. `withAgents()` includes Codex and/or Gemini.

The source path in the Codex message resolves from `$config->commandsPath`, so `withCommandsPath(__DIR__ . '/custom-commands')` produces accurate guidance instead of a hardcoded `.ai/commands/` reference.

### Why this shape — Codex and Gemini

Neither agent has a committable command target boost-core can sensibly write into:

- **Codex** ships custom prompts as deprecated personal-only files under `~/.codex/prompts/`. There is no repo-committed target.
- **Gemini** uses TOML for its command files. Hand-rolling a TOML serializer for one outlier agent fails the cost/coverage test, and there is no upstream convention to mirror (laravel/boost ships no Gemini-command sync, so there's no shape to align with).

Rather than fake-ship into a folder neither agent watches, or silently skip both with no operator signal, 0.7.1 makes the gap discoverable via `boost doctor` and points at the manual authoring path. Re-open if Gemini accepts Markdown command files in a future release, or if a second TOML-emitting agent surfaces.

### Known limitations

- **Kiro skill-vs-command name collision.** A project with both `.ai/skills/deploy/SKILL.md` and `.ai/commands/deploy.md` will have both written to `.kiro/skills/deploy/SKILL.md` — the latter wins by execution order. The collision is invisible to operators today. Phase 4 of the agent-commands-sync work (vendor commands + collision resolution) will close this — tracked.
- **Arguments / placeholders unchanged from 0.7.0.** This release covers argument-less command sync only. The argument transpilation layer remains the Phase 3 deferral documented at 0.7.0.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.7.0...0.7.1

## [0.7.0](https://github.com/sandermuller/boost-core/compare/0.6.2...0.7.0) - 2026-05-25

### Added

#### `withRemoteSkills(...)` — non-Composer skill sources

Declarative consumption of three real-world shapes that don't fit Composer's vendor model:

```php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withRemoteSkills([
        // Bundle mode — fetches the named `.skill` release asset, unzips.
        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', [
            'composer-upgrade',
            'phpstan-developer',
        ]),

        // Path mode — fetches the repo tarball at the given ref, extracts named subdirs.
        // `'.'` covers whole-repo-is-one-skill layouts (blader/humanizer style).
        RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]),
    ]);






































```
Resolved on the next `composer install` / `update` through the existing `BoostAutoSync` hook — no separate command, no separate cache-warm step. First sync hits the network; later syncs are offline-fast (cache lives at `<project>/.boost-remote-cache/`, auto-added to the managed `.gitignore`). Removing an entry prunes its agent-dir output on next sync; removing an entire source prunes every skill it last contributed.

Set `BOOST_GITHUB_TOKEN` to lift anonymous GitHub access from 60 to 5000 requests/hour. CI runs that resolve `withRemoteSkills(...)` cold should always export the token. `BOOST_REMOTE_STRICT=1` escalates any remote-source failure (network unreachable, malformed archive, name-mismatch) to an aborting error; default is warn-and-skip. `boost doctor` lists every declared remote source, flags moving refs (`'main'`, `'latest'`, branch names) with a `⚠`, and reports per-skill cache presence — all offline.

`boost sync --check` is **network-free and side-effect-free**: cold-cache sources are surfaced as `would-fetch` advisories in `SyncResult::errors`, never touching the network or writing to the cache.

#### `SkillRenderer` plugin contract (`@experimental`)

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\ProjectBoostLaravel\Rendering\BladeRenderer;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withSkillRenderers([new BladeRenderer]);






































```
Plugin seam for rendering template-flavored skill bodies (`SKILL.blade.php`, `SKILL.twig`, …) before per-agent fan-out. The dispatcher matches **longest-extension-first** (so `.blade.php` beats `.php` when both are claimed); the implicit `PassthroughRenderer` always handles `.md` and is re-appended after any `withDisabledRenderers([FQCN])` deny-list. Render failures default to warn-and-skip (recorded in `SyncResult::errors`); `BOOST_RENDER_STRICT=1` escalates the first failure to an aborting `SkillRenderException`. The flag is separate from `BOOST_REMOTE_STRICT` so a project can keep renders lenient (a single broken Blade skill should not abort CI) while making remote-source resolution strict, or vice versa.

The contract is `@experimental` — pin to an exact boost-core version if building against it. Lock-in happens after a second non-trivial consumer from a different problem domain validates the shape, mirroring the `FileEmitter` plugin lock-in criteria. Reference consumer: [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) ships a `BladeRenderer` that delegates to laravel/boost's `RendersBladeGuidelines` trait, so `.ai/<pkg>/skill/<name>/SKILL.blade.php` files render with the `$assist = GuidelineAssist` runtime context they expect.

`GuidelineLoader` reuses the same dispatcher — a `BladeRenderer` registered for skills also discovers Blade guidelines in `.ai/guidelines/`. Files whose extension no registered renderer claims are silently skipped, bit-identical to pre-renderer boost-core when no renderer is registered.

#### Caller-controlled vendor injection on `SyncEngine::sync()`

```php
$engine->sync(
    projectRoot: __DIR__,
    injectedVendorSkills: ['laravel/boost' => $skills],
    extraSkillRenderers: [new BladeRenderer],
    injectedVendorGuidelines: ['laravel/boost' => $guidelines],
);






































```
Three new optional parameters for wrapper packages whose source layout `VendorScanner` cannot reach (laravel/boost's `.ai/<pkg>/...` is the motivating case — `sandermuller/project-boost-laravel` uses this seam). Tag-filtered and collision-detected identically to scanned vendors. Same-vendor name collisions between injected and scanned skills throw `SkillSourceCollisionException`, caught in `SyncEngine::sync` and converted to a `SyncResult::errors` entry (lenient) or rethrown (strict). All three default to `[]`; existing call sites are unchanged.

#### `boost where` — skill origin tracing

```bash
vendor/bin/boost where






































```
Lists every skill that would land in agent dirs, grouped by source:

- **`.ai/skills/ (host)`** — host-authored skills, with `(shadows <vendor>)` on overrides.
- **`<vendor/package>`** — Composer-allowlisted vendors publishing via `resources/boost/skills/`.
- **`<vendor/package>` from a `RemoteSkillSource`** — non-Composer sources declared via `withRemoteSkills(...)`.

Same resolution pipeline as `boost sync --check` (tag-filtered, collision-resolved). Caller-injected vendor skills (the wrapper pattern, e.g. `project-boost-laravel`) are NOT visible — those are runtime-only inputs to `SyncEngine::sync()` and the wrapper package owns its own inspection surface.

Complements `boost tags` (lists every tag and which would unlock filtered skills) and `boost doctor` (cache freshness + remote source diagnostics).

#### `SyncResult::renderDeleteAttribution(): ?string`

Canonical attribution renderer for destructive deletes. Returns `null` when nothing was deleted (or in check-mode results), otherwise a multi-line string naming the three possible causes (tag-filter, removed `withRemoteSkills` entry, stale-source prune) followed by the deleted paths.

```php
if ($attribution = $result->renderDeleteAttribution()) {
    $this->warn($attribution); // Laravel artisan
    // or $io->warning($attribution); // Symfony console
}






































```
Single source of truth for the attribution wording — boost-core's own `SyncCommand` and wrapper commands (e.g. `project-boost-laravel`'s artisan `project-boost:sync`, future custom CLIs) use it so the operator-visible delete audit signal stays identical across invocation surfaces. Addresses a gap where wrapper commands that render their own output from `$result->writes` saw the per-line `deleted <path>` action but missed the cause-attribution that `vendor/bin/boost sync` produced. The helper closes the gap symmetrically.

#### `skill-origin-tracing` bundled skill

A `resources/boost/skills/skill-origin-tracing.md` skill that triggers when downstream agents see questions like "why is skill X present", "why is skill Y missing", "where does skill Z come from", or "did host shadow X". Routes them to `boost where` (and `boost tags` for tag-filtered cases) instead of grepping `vendor/`.

#### `Tag` enum gains Laravel-ecosystem cases

`Livewire`, `Volt`, `Inertia`, `Filament`, `Flux`, `Pest`, `Tailwind`. Surfaces the tag vocabulary `laravel/boost`'s bundled skills declare, so `withTags(Tag::Livewire, …)` autocompletes properly. Non-authoritative — string fallback continues to work for any vocabulary the enum doesn't cover.

### Fixed

- **`GuidelineLoader` discovers `.blade.php` (and any renderer-registered extension) in `.ai/guidelines/`.** Previously globbed `*.md` only — host-authored Blade guidelines were silently dropped, causing content loss in `CLAUDE.md` for users with templated guidelines.
- **`FileWriter` refuses to follow user-placed symlinks.** A sync write under `.claude/skills/<name>/` that would resolve through a user-placed symlink (e.g. `.claude/skills/<name>` → `../../.ai/skills/<name>/`) now bails with `WriteAction::SKIPPED_SYMLINK` and surfaces in the summary as `skipped-symlink=N`. Preserves the "live symlinks owned by consumer" contract `SyncEngine::pruneDeadSymlinks()` documented but the write path did not honor.
- **`boost sync --check` is network-free and writes-free.** `RemoteSkillSyncCoordinator::ingestIntoVendorMap()` accepts a `$checkOnly` flag; sources missing from the offline cache are excluded from the ingest call and surfaced as a `would-fetch` advisory in `SyncResult::errors`. Restores the dry-run-purity invariant that pre-companion-refactor consumers relied on.
- **Host-vs-vendor skill shadowing is surfaced.** A host `.ai/skills/<name>/` that shadows an allowlisted-vendor skill of the same name now produces a `<name> shadows <vendor>` line in `SyncCommand`'s output. Plumbed via a new `SyncResult::$hostShadows` field and a `&array $shadows` out-param on `SkillResolver::resolve()`. Existing host-wins precedence and `CollidingSkillsException` semantics unchanged.
- **`boost sync` lists each deleted path inline + attributes the cause.** A delete event (a previously-installed agent-dir skill pruned because its source skill is no longer tag-eligible after a `withTags()` change, or a removed `withRemoteSkills` entry, or a stale-source prune) previously surfaced only as a count in the success summary. `SyncCommand` now emits a warning naming the three possible causes followed by the list of relative paths whenever `deleted > 0`. Behavior unchanged in `--check` mode (where would-delete was already listed).
- **`TagReporter` passes the renderer dispatcher.** `boost tags` and `boost doctor` now discover renderer-claimed extensions (e.g. `.blade.php` with a registered `BladeRenderer`), matching the file set `boost sync` would emit.
- **`RemoteSkillIngester` honors `BOOST_RENDER_STRICT`.** Render failures inside the per-skill loop now escalate via `SkillRenderException` when the flag is set, mirroring `SkillLoader`'s strict path.
- **`locateSkillFile` prefers the canonical `SKILL.<ext>` across all globs.** A remote-skill cache slot containing both `README.md` and `SKILL.blade.php` now correctly loads the latter; previously the first-glob-with-any-match silently ingested README as the skill body.
- **`RemoteSkillIngester` surfaces same-source different-version skill collisions.** Two `withRemoteSkills` entries pointing at the same repo at different versions both listing the same skill name now produce a clear error (lenient: `SyncResult::errors`; strict: `RemoteFetchException`).
- **`RemoteSkillSyncCoordinator` detects collisions with scanned/injected vendor maps.** A remote source sharing a vendor key with an already-populated entry throws `SkillSourceCollisionException`, caught by `SyncEngine::sync` and converted to a `SyncResult::errors` entry.
- **`extraSkillRenderers` no longer shadow user-registered renderers.** Extras are inserted between user renderers and the trailing implicit `PassthroughRenderer`. Final order: `[...user, ...extras, Passthrough]`. With first-match-wins dispatch, the user's `boost.php` registry stays authoritative.
- **`TarballExtractor` archive-path prefix length corrected.** The strip-length for in-archive entry names now includes the `phar://` URL prefix that `PharData` iteration prepends. (Linux CI rendered this as a PharData-vs-`tar` regression and we pivoted to system `tar -xzf` via Symfony Process for portability.)
- **`BundleExtractor::assertNotSymlink` fails closed on unreadable external attributes.** A crafted ZIP that suppresses attribute readability would previously bypass the symlink check; now throws `RemoteExtractException::SYMLINK`.
- **`SkillLoader` falls back to `getPathname()` when `getRealPath()` returns false** (broken symlinks, open_basedir restrictions, file disappears between Finder enumeration and the realpath call). Previously hit a `TypeError` on the false case.
- **`BoostConfigBuilder` distinguishes implicit vs explicit `PassthroughRenderer` by object identity.** A user passing `new PassthroughRenderer()` via `withSkillRenderers` is now treated as a regular renderer for conflict detection.
- **Multi-extension naming fallback fix.** `SKILL.blade.php` with no `name:` frontmatter now resolves to skill name `SKILL` (previously would have been `SKILL.blade`). The dispatcher now returns a `MatchedRenderer { renderer, extension }` tuple so the loader can strip the full matched extension.

### Changed

- **`SkillLoader::load()` accepts a `SkillRendererDispatcher` parameter** (default = passthrough-only). External direct callers that construct a `SkillLoader` are unaffected unless they want to register a non-default renderer. `SyncEngine` builds the per-sync dispatcher from `BoostConfig::skillRenderers` after config-load.
- **`RemoteSkillIngester::ingest()` accepts the same dispatcher**, so remote `.blade.php` skills render through the registered renderer too. The internal `loadOne` was generalized from a hard-coded `SKILL.md` lookup to walk the dispatcher's claimed extensions, falling back to `.md`.
- **`SyncEngine`'s injection-merge logic** extracted to `InjectedVendorMerger` to keep the engine's class-level cognitive complexity tractable.

### Added (internal — public exception class)

- **`SkillSourceCollisionException`** (`src/Sync/SkillSourceCollisionException.php`). Thrown by `InjectedVendorMerger` and `RemoteSkillSyncCoordinator` when caller-config (injected vendor map / remote source declaration) would silently overwrite an existing entry under the same vendor key. Caught in `SyncEngine::sync()` and converted to a `SyncResult` with the message as an error — consumers that wrap `sync()` and expect it to never throw on user-config issues keep working. Distinct from `CollidingSkillsException` (which models cross-vendor name collisions detected by `SkillResolver`).

### Upgrade notes

No migration required from 0.6.x. The three additive surfaces (`withRemoteSkills`, `SkillRenderer`, `SyncEngine::sync` injection params) are opt-in; existing projects keep working unchanged. See `UPGRADING.md` for adoption notes.

`@experimental` APIs (`SkillRenderer`, the three injection params): pin to an exact boost-core version (`"sandermuller/boost-core": "0.7.0"` rather than `"^0.7.0"`) if your project depends on the precise shape. The shape survived the entire rc cycle without churn, but lock-in waits for a second non-trivial consumer from a different problem domain.

### Companion package status

[`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel) — the reference Laravel companion that consumes laravel/boost-bundled skills via the injection seam, ships its own `BladeRenderer`, and exposes a Laravel artisan `project-boost:sync` command — accepts 0.7.0 stable on its existing `^0.7.0-rc1` constraint without composer.json changes. Cut a `composer update sandermuller/boost-core` to pick up stable.

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.6.2...0.7.0

## [0.6.2](https://github.com/sandermuller/boost-core/compare/0.6.1...0.6.2) - 2026-05-23

### Fixed

- **`boost sync` emits a one-line note when tagged vendor skills are silently filtered out.** Triggered when (a) the consumer's `withTags()` is empty AND (b) at least one vendor skill was dropped specifically by tag-mismatch:
  
  ```
  ! [NOTE] N tagged skill(s) currently filtered out — your `withTags()` is empty.
          Run `vendor/bin/boost tags` to see them.
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  The nudge is precise about its cause — `withExcludedSkills` denials and malformed-frontmatter drops are NOT counted, so the message never misleads consumers who intentionally excluded skills or have a broken vendor manifest. Per-vendor tag-mismatch drops are summed without cross-vendor name deduplication, so two vendors each hiding a same-named skill count as two.
  
  Consumers with `withTags(...)` already declared see no nudge — explicit filtering is intentional, not noise.
  
- **`SkillTagFilter::filter()` now returns `droppedByTag: int`** alongside `droppedNames` — the data-flow change that powers the nudge. Existing callers of `kept` and `droppedNames` are unaffected; the return is additive.
  

### Upgrade notes

`composer require sandermuller/boost-core:^0.6.2` — or nothing at all if you already allow `^0.6`, since this is a patch. No behaviour change for consumers who already declare `withTags(...)` (the common explicit-filtering case). Family packages constrained to `boost-core ^0.6` receive 0.6.2 transitively, no re-tag needed.

If your next `composer install` surfaces the new note pointing at `vendor/bin/boost tags`, you have skills available you have not opted into — `boost tags`'s "Filtered skills you could enable" section shows the gap and the tag(s) to add to `withTags(...)` to receive them. If the filtering was intentional, declare the tags you actually want (any non-empty `withTags()` silences the nudge) and `boost tags` will continue to show the full classification.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.6.1...0.6.2

## [0.6.1](https://github.com/sandermuller/boost-core/compare/0.6.0...0.6.1) - 2026-05-22

A visibility fix on top of 0.6.0: `BoostAutoSync::run` (and `runWithSummary`) went silent on a delete-only sync — a `composer install` that pruned generated AI files showed no trace of having done so. The sync itself worked correctly; only its summary line was missing the data. Additive and non-breaking.

### Fixed

- **`SyncCommand` success summary now reports `deleted=%d`.** Both the project-scope (`Sync done. wrote=%d, unchanged=%d, deleted=%d.`) and user-scope (`[<pkg> → <home>] Sync done. wrote=%d, unchanged=%d, deleted=%d.`) summaries include a delete count alongside `wrote` and `unchanged`. The data was already in `SyncResult` (`countByAction(WriteAction::DELETED)`) — only the summary string omitted it.
  
- **`BoostAutoSync::run` surfaces delete-only syncs.** 0.6.0's `run()` gated on `wrote>0`, so a sync that *only* deleted files — e.g. an upgrade pruning a generated skill dir after a `withTags()` change filters a vendor skill out — finished silently. The gate is now `wrote>0 OR deleted>0`: routine no-op installs (`wrote=0, deleted=0`) still stay quiet, and any install that actually changed files announces it. `runWithSummary` is unchanged behaviorally, but the line it prints now carries the new `deleted=%d` field.
  

### Upgrade notes

`composer require sandermuller/boost-core:^0.6.1` — or nothing at all if you already allow `^0.6`, since this is a patch. No behaviour change for the routine no-op case (still silent); the change is purely additive output on installs that actually wrote or pruned files. Family packages constrained to `boost-core ^0.6` receive 0.6.1 transitively, no re-tag needed.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.6.0...0.6.1

## [0.6.0](https://github.com/sandermuller/boost-core/compare/0.5.5...0.6.0) - 2026-05-22

boost-core is no longer a Composer plugin. It ships as a plain `type: library` — no install-time code, no `allow-plugins` trust prompt, no install-time execution surface — and every command runs through the standalone `vendor/bin/boost`. This release also adds Symfony 8 support, `.ai/commands/` sync, and a frontmatter-free guideline tagging path.

**This is a breaking release.** See [`UPGRADING.md`](https://github.com/SanderMuller/boost-core/blob/main/UPGRADING.md) for the full 0.5 → 0.6 migration.

### Breaking

- **The Composer plugin is removed; boost-core is now `type: library`.** It runs no install-time code of its own. Consequences:
  - **`composer boost:*` commands are gone.** Every command runs through the standalone binary, with the `boost:` prefix dropped: `composer boost:sync` → `vendor/bin/boost sync`, and likewise for `install`, `scan`, `doctor`, `tags`, and `new`.
  - **Auto-sync is no longer automatic.** The plugin re-ran `boost sync` on every `composer install` / `composer update`. To keep that, wire the `BoostAutoSync` script callback into your own project's `composer.json` (see `UPGRADING.md`); otherwise run `vendor/bin/boost sync` yourself, e.g. in CI.
  - A consumer `composer.json` can drop the `sandermuller/boost-core` entry from `config.allow-plugins` — boost-core is no longer a plugin. Leaving it is harmless.
  

### Added

- **`boost sync --scope=user --all`** — user-scope-syncs every installed package that ships `resources/boost/skills/`, in one command. The explicit replacement for the plugin's automatic `composer global` sync; run it once after `composer global require`. User scope publishes wholesale — there is no `boost.php` in play, so tag filters and the vendor allowlist (both project-scope controls) do not apply.
  
- **Symfony 8 support.** `symfony/console`, `symfony/finder`, `symfony/process`, and `symfony/yaml` now allow `^7.0||^8.0`, so boost-core installs alongside Laravel 13. Symfony 7.x remains fully supported.
  
- **`.ai/commands/` sync.** Reusable prompt templates placed in `.ai/commands/*.md` fan out to the six agents with a command surface — Claude Code, Cursor, Copilot, Junie, OpenCode, and Amp. The source directory is overridable with `->withCommandsPath()` in `boost.php`.
  
- **Frontmatter-free guideline tagging.** A sidecar `resources/boost/guidelines/.boost-tags.yaml` manifest maps guideline filenames to tags, letting a package tag a guideline for conditional sync without putting a `---` frontmatter block in the guideline file itself. This is necessary for `laravel/boost` compatibility, which renders guideline frontmatter literally. A guideline's own frontmatter still wins when both sources are present.
  
- **`Tag::Database`** — a new case in the convenience `Tag` enum.
  

### Changed

- **`BoostAutoSync::run`** — the callback wired into `post-install-cmd` / `post-update-cmd` — now prints the one-line sync summary only when the sync wrote at least one file, staying silent on a no-op install. `BoostAutoSync::runWithSummary` keeps its always-print behavior for user-invoked scripts (`composer sync-ai`, etc.).

### Upgrade notes

`composer require --dev sandermuller/boost-core:^0.6.0`. This is a breaking release — read [`UPGRADING.md`](https://github.com/SanderMuller/boost-core/blob/main/UPGRADING.md) before bumping: the command rename (`composer boost:*` → `vendor/bin/boost`), the auto-sync wiring, and the `--scope=user --all` flow for globally-installed skill packages are all covered there. Family packages constrained to `boost-core ^0.5` must widen the constraint to `^0.6` to receive this release.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.5...0.6.0

## [0.5.5](https://github.com/sandermuller/boost-core/compare/0.5.4...0.5.5) - 2026-05-22

### Added

- **`withExcludedGuidelines()`** in `boost.php` — drop a specific vendor guideline by `vendor/package:guideline-name`, regardless of tags:
  
  ```php
  ->withExcludedGuidelines(['acme/pack:database-safety'])
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  Guideline tag-filtering (0.5.3) can only drop a guideline that declares `metadata.boost-tags`. A guideline shipped *frontmatter-free* — as a `laravel/boost`-compatible package must, since `laravel/boost` rejects guideline frontmatter — is untagged, and an untagged guideline always ships. The deny-list is the only lever that reaches it. `GuidelineTagFilter` now has full parity with `SkillTagFilter`'s exclude step.
  
- **`excluded (withExcludedGuidelines)` guideline status** in `boost:tags` and `boost:doctor` — the guideline table reports an excluded guideline the same way the skill table already reports an excluded skill.
  

### Changed

- The README conditional-filtering section and the shipped `boost-config-shape` skill document `withExcludedGuidelines()` and when to reach for it instead of tag-filtering.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.4...0.5.5

## [0.5.4](https://github.com/sandermuller/boost-core/compare/0.5.3...0.5.4) - 2026-05-21

### Added

- **`BoostAutoSync::syncUserScope()` and `BoostAutoSync::syncUserScopeOnce()`** — an event-free pair that lets a `composer global`-installed CLI tool self-sync its bundled skills from its own bin script, with no Composer plugin involved.
  - `syncUserScope($packageRoot)` runs a user-scope sync (into `~/.{agent}/skills/<vendor>__<package>/`) in-process — no binary resolution, no subprocess. Returns `0`/`1`, honors `BOOST_SKIP_AUTOSYNC`, and never throws: a tool keeps running even if its self-sync fails (a failure prints a single stderr line).
  - `syncUserScopeOnce($packageRoot, $packageName)` gates that behind a per-version sentinel — `${XDG_CACHE_HOME:-$HOME/.cache}/boost/synced/<vendor>-<package>@<version>`, with a `%USERPROFILE%\.cache` rung for Windows and a system-temp fallback. Drop it on a tool's bin's first line: it re-syncs once per version bump and costs nothing on every run after.
  

### Changed

- **README gains a "Self-sync for globally-installed CLI tools" section** under the script-callback docs, with the four-line bin snippet.

### Upgrade notes

`composer require sandermuller/boost-core:^0.5.4` — or nothing at all if you already allow `^0.5`, since this is a patch. No behaviour change for existing installs: the new helper is opt-in API a tool author calls explicitly, and the plugin's `post-autoload-dump` / `post-install-cmd` auto-sync paths are unchanged. Family packages already constrained to `boost-core ^0.5` receive 0.5.4 transitively, no re-tag needed.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.3...0.5.4

## [0.5.3](https://github.com/sandermuller/boost-core/compare/0.5.2...0.5.3) - 2026-05-21

### Added

- **Conditional tag-filtering for vendor guidelines.** A guideline declares `metadata.boost-tags` in its frontmatter and syncs only when its tag set is a subset of the project's `withTags()` — the same `itemTags ⊆ projectTags` rule skills already follow. An untagged guideline carries the empty set and always ships, so existing setups are unaffected; a malformed `metadata.boost-tags` fails closed (the guideline ships nowhere rather than leaking). Filtering runs in `SyncEngine::resolveGuidelines()` before collision resolution — host `.ai/guidelines/` are never filtered, only vendor guidelines.
- **`boost:tags` and `boost:doctor` now report guidelines.** Both gain a guideline tag-status table (eligible / filtered / invalid) beside the existing skill one. The tag vocabulary in use, the "declared but matched by nothing" hygiene hint, and near-duplicate detection now span skills **and** guidelines, gathered in a single vendor-discovery pass.

### Changed

- **README `## Conditional skill filtering`** documents the guideline path, including one portability caveat: a guideline carrying frontmatter is fine for boost-core but not for every consumer (`laravel/boost` expects frontmatter-free guideline Markdown) — tag a guideline only when boost-core is its sole delivery path.
- The shipped `boost-config-shape` skill's `withTags()` guidance is generalized from "skill" to "skill or guideline".

### Known limitation

Filtering an agent's *entire* guideline set to empty leaves the previously-generated file (`CLAUDE.md`, `AGENTS.md`, …) in place — boost cannot distinguish its own output from a hand-written file without an ownership marker, so it does not delete it. The common case (some guidelines survive the filter) rewrites the file correctly. A skills-only project is unaffected.

### Internal

- The frontmatter tag-parser is extracted to a shared `BoostTags` value (was private to `SkillLoader`); `SkillLoader` and `GuidelineLoader` both delegate to it, so skills and guidelines parse `metadata.boost-tags` identically. 229 tests / 593 assertions; Rector, Pint, PHPStan clean.

### Upgrade notes

`composer require sandermuller/boost-core:^0.5.3` — or nothing at all if you already allow `^0.5`, since this is a patch. No behaviour change for existing installs: guideline filtering activates only when a vendor guideline declares tags, and the `boost:tags` / `boost:doctor` guideline tables are purely additive. Family packages already constrained to `boost-core ^0.5` receive 0.5.3 transitively, no re-tag needed.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.2...0.5.3

## [0.5.2](https://github.com/sandermuller/boost-core/compare/0.5.1...0.5.2) - 2026-05-21

### Changed

- **The shipped `boost-config-shape` skill gains a discover-then-suggest workflow.** When an agent works on a project's `boost.php`, the skill now directs it to: run `boost:doctor` to discover which vendor packages publish skills (allowlisted vs discovered-but-not), allowlist the relevant ones, run `boost:tags` to see the tag vocabulary the allowlisted skills declare, then propose `withTags()` entries matched to the project's actual stack (framework, issue tracker, CI host) and `withExcludedSkills()` entries for specific unwanted vendor skills — each suggestion carrying its reasoning, for the maintainer to decide. Only tags an installed skill actually declares are suggested.

### Upgrade notes

`composer require sandermuller/boost-core:^0.5.2` — or nothing at all if you already allow `^0.5`. Docs-only: no code, no behaviour change. The updated skill reaches a project on its next `boost:sync` after the upgrade.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.1...0.5.2

## [0.5.1](https://github.com/sandermuller/boost-core/compare/0.5.0...0.5.1) - 2026-05-21

### Added

- **`composer boost:tags`** — a focused skill-tag discovery command. Reports the project's declared tags (`withTags()`), the tag vocabulary in use across installed vendor skills, a per-skill tag-status table (eligible / filtered / excluded / invalid), likely-typo hints, and a roll-up of which tags to add to `withTags()` to unlock currently-filtered skills. The same report is also a section of `composer boost:doctor` — `boost:tags` is the standalone, focused view.
- **An "enable roll-up" in `boost:doctor`.** The existing "Skill tags" section now ends with a grouped summary — `declare jira → vendor/pack:skill-a, vendor/pack:skill-b` — so it is clear at a glance which tags would unlock which filtered skills, rather than reading it off the per-skill table.
- **`SanderMuller\BoostCore\Enums\Tag::Github`** — a new enum case (`'github'`) for the GitHub forge (pull requests, Actions, releases, the `gh` CLI), distinct from the existing `Tag::GithubIssues` (`'github-issues'`, issue tracking). The `Tag` enum remains non-authoritative — any string is still a valid tag; this only adds autocomplete for a common one.

### Changed

- **README gains a `## Skills from packages` section** — documents how a Composer package ships skills (`resources/boost/skills/<name>/SKILL.md`) and how a consuming project receives them by allowlisting the vendor, leading into the conditional-filtering section.
- The shipped `boost-config-shape` skill now spells out that the `boost:install` / `boost:scan` picker's first-party pre-check is picker UX only — `boost:sync` gates purely on the explicit `withAllowedVendors()` list, so a hand-written `boost.php` with an empty array syncs zero vendor skills.

### Internal

- The `update-changelog` CI workflow now clears `## [Unreleased]` after prepending each release section — dev-era entries previously accumulated there and duplicated the release.
- Two stale `skill.yaml` docstring references corrected to `metadata.boost-tags` (leftover from the 0.5.0 tags-location move). 210 tests / 567 assertions; CI green.

### Upgrade notes

`composer require sandermuller/boost-core:^0.5.1` — or nothing at all if you already allow `^0.5`, since this is a patch. No behaviour change for existing installs: `boost:tags` is a new opt-in command and the `boost:doctor` roll-up is purely additive. Family packages already constrained to `boost-core ^0.5` receive 0.5.1 transitively, no re-tag needed.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.5.0...0.5.1

## [0.5.0](https://github.com/sandermuller/boost-core/compare/0.4.0...0.5.0) - 2026-05-20

### Added

- **Conditional skill filtering by tags.** A skill declares tags in its `SKILL.md` frontmatter under the Agent Skills standard's sanctioned `metadata` extension point — `metadata.boost-tags`, a space-delimited string. A project declares the tags it wants in `boost.php` via `->withTags(Tag::Php, 'jira', ...)`. During project-scope `boost:sync` a vendor skill is fanned out only when every tag it declares is among the project's tags, so a project never receives — and the AI agent's skill-selection index never even sees the `description` of — skills irrelevant to it. Untagged skills always ship. A malformed `metadata.boost-tags` **fails closed** (the skill ships nowhere) rather than silently becoming untagged and leaking. When a skill stops shipping, its previously-synced agent-directory output is pruned — safely: only directories boost-core itself wrote, never through symlinks; `boost:sync --check` reports the would-be deletion as drift without performing it.
- **`->withExcludedSkills(['vendor/package:skill-name'])`** — a per-skill deny-list that drops specific vendor skills regardless of tags, for when you want a vendor's bundle minus one entry.
- **`SanderMuller\BoostCore\Enums\Tag` enum** — a non-authoritative convenience enum giving `boost.php` authors autocomplete for common tags without closing the (open, free-string) tag vocabulary: `->withTags()` accepts both `Tag` cases and raw strings.
- **`composer boost:doctor` tag report.** A new section reports the project's declared tags, the runtime union of tags across installed allowlisted skills, per-skill tag status (eligible / filtered / excluded / invalid), and likely-typo hints for near-duplicate tags.
- **boost-core now distributes a `boost-config-shape` skill** (`resources/boost/skills/`) — installing boost-core gives downstream AI agents a skill describing `boost.php`'s structure, including the new `withTags()` / `withExcludedSkills()` calls.

### Changed

- **`BoostConfigWriter` is now format-preserving.** When `boost.php` is rewritten (e.g. `boost:install` re-running against an existing file), comments, blank-line layout, and unrelated formatting are preserved instead of being normalised away — the writer mutates only the nodes it must, via PHP-Parser's `printFormatPreserving` printer.
- **The `Agent` enum is emitted in short form** (`Agent::CLAUDE_CODE`, not `\SanderMuller\BoostCore\Enums\Agent::CLAUDE_CODE`) when the `boost.php` being written already imports the enum, so generated config matches hand-written style.

### Fixed

- **No more spurious self-collision warning during `composer global` operations.** boost-core's own `dev-main` branch-alias surfaced the root package as a Composer `AliasPackage`, which `runGlobalSync` then iterated a second time — tripping the basename-collision guard against boost-core itself. Alias entries are now skipped during global sync, and the stale `dev-main → 0.3.x-dev` branch-alias has been dropped from `composer.json` entirely (a regularly-tagged plugin gains nothing from it).

### Internal

- New `SkillTagFilter`, `SkillTagDiagnostics`, `FilteredSkillPruner`, `Enums\Tag`, and `Config\BoostConfigPrinter` classes carry the feature; the tag filter runs in `SyncEngine::resolveSkills()` *before* collision resolution so only shippable skills enter resolution.

### Upgrade notes

`composer require sandermuller/boost-core:^0.5.0`. The upgrade is hands-off — there is no migration and no behaviour change for existing installs. Tag filtering stays inert until a skill declares `metadata.boost-tags` *and* a project declares `->withTags()`; until then every skill ships exactly as before. Bundle packages (`sandermuller/project-boost`, `sandermuller/package-boost-php`, `sandermuller/package-boost-laravel`) roll the new boost-core through transitively as they re-tag.

> **Note for skill publishers:** tagging a skill that consumers already receive is a *consumer-visible* change — a project that has not declared the matching tag will stop receiving that skill on its next sync. Tag skills from the start, or treat adding a tag to a shipped skill as a breaking change for that package.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.4.0...0.5.0

## [0.4.0](https://github.com/sandermuller/boost-core/compare/0.3.4...0.4.0) - 2026-05-20

### Breaking changes

- **User-scope skill paths now vendor-namespaced.** Pre-0.4 layout: `~/.{agent}/skills/<package-basename>/...`. Post-0.4: `~/.{agent}/skills/<vendor>__<package>/...`. The `/` is replaced with `__` (double underscore) — a sequence the Composer name spec forbids inside vendor or project parts, so the slug mapping is injective: distinct valid package names always produce distinct slugs (no `vendor-a/foo` vs `vendor/a-foo` style ambiguity that a `-` separator would admit).
  - **Auto-migration:** first `syncUser()` against each installed package post-upgrade detects `~/.{agent}/skills/<basename>/`, verifies its contents are reproducible from THIS package's `resources/boost/skills/` tree (ownership check), and renames to the new slug. Idempotent — subsequent syncs find no old dir and no-op. Safe — skipped when the new-slug dir already exists (don't overwrite a fresh sync's output with stale data).
  - **Pre-0.2 collision states require manual cleanup.** The collision-detection guard in `BoostCorePlugin::runGlobalSync` shipped in 0.2.0; pre-0.2, two installed packages with the same basename both wrote to `~/.{agent}/skills/<basename>/` (last-writer wins). The ownership check refuses to migrate such a dir (foreign files mean mis-attribution risk), and the legacy dir is left in place for the user to triage. To resolve: inspect `~/.{agent}/skills/<basename>/`, copy any wanted files to the right `~/.{agent}/skills/<vendor>__<basename>/` dir manually, then `rm -rf ~/.{agent}/skills/<basename>/`.
  - **For scripts / docs referencing user-scope paths:** update any hard-coded `~/.{agent}/skills/<basename>/` references to `~/.{agent}/skills/<vendor>__<basename>/`. Boost-core's collision-detection code path stays in place defensively but is unreachable for any valid Composer package name.
  - `SyncEngine::packageSuffix(string)` (a public static helper exposed for the plugin's collision tracking) now returns the slug rather than the basename. Adjust any direct callers; the existing `packageBasename(string)` helper preserves the old behaviour.
  

### Internal

- **Migration logic extracted to `Sync\UserScopeMigrator`.** Keeps `SyncEngine`'s cognitive complexity under PHPStan's class-level threshold and gives the ownership-check semantics a clear home with its own unit-test surface.
- **Three new integration tests** under `tests/Integration/UserScopeSyncTest.php`: happy-path migration (legacy dir → new slug, fresh sync overwrites in place), idempotency (second sync no-ops cleanly), and the new ownership-skip case (foreign content in legacy dir leaves it untouched). Total suite: 153 tests / 437 assertions; CI green across the matrix.
- **README + UPGRADING refreshed** with the new path shape and the manual-cleanup steps for pre-0.2 collision states.

### Upgrade notes

`composer require sandermuller/boost-core:^0.4.0`. Bundle packages (`sandermuller/project-boost`, `sandermuller/package-boost-php`, `sandermuller/package-boost-laravel`) will roll the new boost-core through transitively as they re-tag.

For consumers without pre-0.2 install history, the upgrade is hands-off — the auto-migration moves legacy dirs the first time each `composer global require`'d package re-syncs. Pre-0.2 collision states (two same-basename packages installed before 0.2 shipped the warn-and-skip guard) are the only case requiring manual triage; see the `UPGRADING.md` section above.

**Full changelog:** https://github.com/SanderMuller/boost-core/compare/0.3.4...0.4.0

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
