# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.6.2...HEAD)

### Added

- **`SkillRenderer` plugin contract** (`@experimental`, `src/Contracts/SkillRenderer.php`). Lets downstream packages render template-flavored skill bodies (Blade, Twig, …) before per-agent fan-out. Default registry is `PassthroughRenderer` claiming `.md` — behavior is bit-identical to pre-renderer boost-core when no renderer is registered. Register via `BoostConfigBuilder::withSkillRenderers([new BladeRenderer])`; deny by FQCN via `withDisabledRenderers([Foo::class])`. Dispatcher uses longest-extension-first match (so `.blade.php` beats `.php`); first-registered-wins resolves same-extension ties. The implicit `PassthroughRenderer` is re-appended after the deny-list so `.md` always renders. Spec: `internal/specs/skill-renderer-plugin.md`. Reference consumer: [`sandermuller/project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)'s `BladeRenderer` which delegates to laravel/boost's `RendersBladeGuidelines` trait for `$assist = GuidelineAssist` runtime context.

- **`BOOST_RENDER_STRICT` env flag.** Mirrors `BOOST_REMOTE_STRICT` but for renderer exceptions. Lenient (default): the offending file is dropped from the sync and the failure is recorded in `SyncResult::errors`. Strict (`=1`/`true`/`on`): the first failure throws `SkillRenderException`, aborting the sync. Kept separate from `REMOTE_STRICT` so a project can keep renders lenient while remote-source resolution is strict (or vice versa).

- **Caller-controlled vendor injection** via three new `SyncEngine::sync()` parameters:
  - `injectedVendorSkills: array<string, list<Skill>>` — pre-built skills keyed by source vendor. Tag-filtered + collision-detected identically to scanned vendors. Covers ecosystems whose layout `VendorScanner` does not match (laravel/boost's `.ai/<pkg>/...` is the motivating case).
  - `injectedVendorGuidelines: array<string, list<Guideline>>` — same shape for guidelines.
  - `extraSkillRenderers: list<SkillRenderer>` — additional renderers to append to `BoostConfig::skillRenderers` for one sync transaction. Lets a wrapper package guarantee its renderer is registered without forcing users to wire it in `boost.php`.

  Same-vendor name collisions throw explicitly (`SkillResolver` only catches cross-vendor dupes, so an injected skill overlapping a scanned vendor's would silently first-win without this guard).

### Changed

- **`SkillLoader::load()` accepts a `SkillRendererDispatcher` parameter** (default = passthrough-only). External direct callers that construct a `SkillLoader` are unaffected unless they want to register a non-default renderer. `SyncEngine` builds the per-sync dispatcher from `BoostConfig::skillRenderers` after config-load (the dispatcher cannot live as a ctor field — `BoostConfig` is only known inside `sync()`).

- **`RemoteSkillIngester::ingest()` accepts the same dispatcher**, so remote `.blade.php` skills render through the registered renderer too. The internal `loadOne` was generalized from a hard-coded `SKILL.md` lookup to walk the dispatcher's claimed extensions, falling back to `.md`.

- **Multi-extension naming fallback fix.** `SKILL.blade.php` with no `name:` frontmatter now resolves to skill name `SKILL` (previously would have been `SKILL.blade` because `getFilenameWithoutExtension()` only strips the last `.`-segment). The dispatcher now returns a `MatchedRenderer { renderer, extension }` tuple so the loader can strip the full matched extension.

- **`SyncEngine`'s injection-merge logic** extracted to `InjectedVendorMerger` to keep the engine's class-level cognitive complexity tractable.

### Fixed

- **`Tag` enum gains `Livewire`, `Volt`, `Inertia`, `Filament`, `Flux`, `Pest`, `Tailwind`.** Surfaces the Laravel-ecosystem tag vocabulary laravel/boost's bundled skills declare, so `withTags(Tag::Livewire, …)` autocompletes properly. Non-authoritative — string fallback continues to work for any vocabulary the enum doesn't cover.

- **`boost sync` lists each deleted path inline.** A delete event (a previously-installed agent-dir skill pruned because its source skill is no longer tag-eligible after a `withTags()` change) previously only surfaced as a count in the success summary. The operator-facing concern: an unexpected delete is destructive and easy to miss. Now `SyncCommand` emits a warning followed by the list of relative paths whenever `deleted > 0`. Behavior unchanged in `--check` mode (where would-delete was already listed). Surfaced by a dogfooding consumer who saw their `fluxui-development` + `wayfinder-development` agent dirs disappear after a `withTags()` filter narrowed.

- **`GuidelineLoader` discovers `.blade.php` (and any renderer-registered extension) in `.ai/guidelines/`.** Previously globbed `*.md` only — host-authored Blade guidelines (e.g. `core.blade.php` for a project-specific block) were silently dropped, causing content loss in `CLAUDE.md` for users with templated guidelines. Now mirrors `SkillLoader`'s shape: extension handling driven by the same `SkillRendererDispatcher`, renderers reused across the skill and guideline surfaces (a `BladeRenderer` registered for skills works for guidelines too). Files whose extension no registered renderer claims are silently skipped — behavior bit-identical to pre-renderer boost-core when no renderer is registered. Surfaced by a dogfooding consumer whose `CLAUDE.md` lost an 11kB Blade-rendered project-specific block on first sync.

- **`FileWriter` refuses to follow user-placed symlinks** — `SKIPPED_SYMLINK` `WriteAction` (new). Previously a sync write of `.claude/skills/<name>/SKILL.md` would silently overwrite the file behind a user-placed symlink (e.g. `.claude/skills/<name>` → `../../.ai/skills/<name>/`), destroying the source the symlink was meant to read from. The writer now walks each path segment under `$projectRoot` and bails with `WriteAction::SKIPPED_SYMLINK` on the first one that is a symlink. Mirrors the "live symlinks owned by consumer" contract that `SyncEngine::pruneDeadSymlinks()` documented but the write path did not honor. `SyncCommand` surfaces the count as `skipped-symlink=%d` in its summary plus a warning line pointing the user at `--check` to inspect paths. Surfaced by a dogfooding consumer with 17 dir-symlinks.

- **`extraSkillRenderers` no longer shadow user-registered renderers.** Extras are now inserted between user renderers and the trailing implicit `PassthroughRenderer`. Final order: `[...user, ...extras, Passthrough]`. With first-match-wins dispatch, the user's `boost.php` registry stays authoritative; extras override only the implicit passthrough — the documented use case.

- **`RemoteSkillIngester` honors `BOOST_RENDER_STRICT`.** Render failures inside the per-skill loop now escalate via `SkillRenderException` when the flag is set, mirroring `SkillLoader`'s strict path. Previously only `BOOST_REMOTE_STRICT` could promote a remote-skill render failure to a sync abort.

- **`locateSkillFile` prefers the canonical `SKILL.<ext>` across all globs.** A remote-skill cache slot containing both `README.md` and `SKILL.blade.php` now correctly loads the latter. Previously the first-glob-with-any-match returned, which silently ingested README as the skill body when alphabetical order put it first.

- **`RemoteSkillIngester` surfaces same-source different-version skill collisions.** Two `withRemoteSkills` entries pointing at the same repo at different versions both listing the same skill name now produce a clear error (lenient: `SyncResult::errors`; strict: `RemoteFetchException`). Previously the second skill silently first-won.

- **`RemoteSkillSyncCoordinator` detects collisions with scanned/injected vendor maps.** A remote source sharing a vendor key with an already-populated entry now throws a `SkillSourceCollisionException` caught by `SyncEngine::sync` and converted to a `SyncResult::errors` entry. Mirrors `InjectedVendorMerger`'s invariant for the remote path.

- **`TarballExtractor` archive-path prefix length corrected.** The strip-length for in-archive entry names now includes the `phar://` URL prefix that `PharData` iteration prepends (was off by 7 chars). Error messages now name the right path; archive entries flagged as path-traversal still rejected, but for the right reason.

- **`BundleExtractor::assertNotSymlink` fails closed on unreadable external attributes.** A crafted ZIP that suppresses attribute readability would previously bypass the symlink check; now throws `RemoteExtractException::SYMLINK` with a clear message.

- **`SkillLoader` falls back to `getPathname()` when `getRealPath()` returns false** (broken symlinks, open_basedir restrictions, file disappears between Finder enumeration and the realpath call). `Skill::sourcePath` is typed `string`; the previous direct assignment would have thrown `TypeError` on the false case.

- **`BoostConfigBuilder` distinguishes implicit vs explicit `PassthroughRenderer` by object identity.** A user passing `new PassthroughRenderer()` via `withSkillRenderers` is now treated as a regular renderer for conflict detection — including colliding with other md-claiming renderers. The implicit passthrough appended by the builder is still silently yielded on user-registered `md` claims.

### Added (internal — public exception class)

- **`SkillSourceCollisionException`** (`src/Sync/SkillSourceCollisionException.php`). Thrown by `InjectedVendorMerger` and `RemoteSkillSyncCoordinator` when caller-config (injected vendor map / remote source declaration) would silently overwrite an existing entry under the same vendor key. Caught in `SyncEngine::sync()` and converted to a `SyncResult` with the message as an error — consumers that wrap `sync()` and expect it to never throw on user-config issues keep working. Distinct from `CollidingSkillsException` (which models cross-vendor name collisions detected by `SkillResolver`).

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
