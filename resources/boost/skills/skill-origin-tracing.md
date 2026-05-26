---
name: skill-origin-tracing
description: 'Trace where a skill, guideline, or command comes from or why one is missing/unexpected. Triggers when the user asks: "why is skill X present", "why is guideline Y missing", "where does command Z come from", "which package ships X", "is X from host or vendor", "did host shadow X", "what will boost sync write", "why did boost sync remove X", "which version of X is being used".'
---

# Trace a skill, guideline, or command origin

## When to apply

Activate this skill whenever the user wants to know **where a skill, guideline, or command came from, why it's present, why it's missing, or which copy is winning when two sources publish the same name**. Don't guess from the codebase — use `boost where`.

Common phrasings to watch for:

- "Why is `<name>` present / missing / not in my .claude/skills?"
- "Where does `<name>` come from?"
- "Which package ships `<name>`?"
- "Is `<name>` from my `.ai/` or from a vendor package?"
- "Did my host override `<name>`?"
- "What will `boost sync` write?"
- "Why did `boost sync` remove `<name>`?"
- "Which version of `<name>` is being used?"

## Primary command — `boost where`

Run from the project root:

```bash
vendor/bin/boost where
```

Output is split into three top-level sections — **SKILLS**, **GUIDELINES**, **COMMANDS** — each grouped by origin. Empty sections are silently omitted (a host-only project with no commands won't render a COMMANDS header at all). Within each section, every group is labeled with one of these tags:

- **`host`** — `.ai/skills/`, `.ai/guidelines/`, `.ai/commands/` (project-authored). On the SKILLS section, a `(shadows <vendor>)` annotation flags host skills that override an allowlisted-vendor skill of the same name.
- **`vendor`** — a Composer-allowlisted vendor publishing via `resources/boost/skills/` or `resources/boost/guidelines/`.
- **`remote`** — non-Composer source declared via `withRemoteSkills(...)` in `boost.php` (GitHub `.skill` bundles, repo subdirs). Skills only — there is no remote-guideline or remote-command pipeline today.
- **`vendor+remote`** — rare but legal: an `<owner>/<repo>` key participates in both a scanned Composer vendor AND a `withRemoteSkills(...)` declaration (skill names must still be unique upstream).

Items that DO NOT appear in `boost where` output were dropped by the tag filter, by `withExcludedSkills` / `withExcludedGuidelines`, or by a malformed `metadata.boost-tags` ("fail closed"). Cross-check with `boost tags` (see below) to see what was filtered and why. Commands are host-only today (vendor commands are a deferred backlog item) so a missing command is most likely just a missing file under `.ai/commands/`.

## Diffing a shadowed skill — `boost where --diff=<name>`

When the SKILLS section shows a `(shadows <vendor>)` annotation, the natural follow-up question is "what exactly differs in this override". Pass the skill name as `--diff=<name>`:

```bash
vendor/bin/boost where --diff=deploy
```

Three outcomes:

- **Unified diff** — the host file and vendor file differ; `---` / `+++` headers name both paths and the diff body shows the line-level changes (vendor = `---`, host = `+++`).
- **Byte-identical** — the override earns nothing; the command prints a friendly hint to remove the host copy and ship the vendor version.
- **Not a shadow** — the named skill doesn't exist host-side, or no allowlisted vendor publishes a skill of the same name. Friendly error pointing back at `boost where` for the resolved origin map.

`--diff` shares the resolution pipeline with `boost sync` — renderers (Blade etc.) and `withTags()` filtering apply, so a `.blade.php` skill or a tag-filtered vendor copy diffs against what would actually ship, not what's on disk pre-filter.

## Companion-injected skills (`project-boost-laravel` etc.)

`boost where` shows host + scanned-vendor + remote skills only. Caller-injected skills — the wrapper pattern used by `sandermuller/project-boost-laravel` to surface `laravel/boost`-bundled skills — are runtime-only inputs to `SyncEngine::sync()` and aren't visible from the boost-core CLI.

If the user is in a Laravel project and asks where a `laravel/boost`-flavored skill (e.g. `pest-testing`, `livewire-development`, `folio-routing`) comes from:

- The wrapper package owns the injection. Look for `project-boost:sync` in `composer.json` scripts or the Laravel kernel.
- The discovery source is `vendor/laravel/boost/.ai/<pkg>/skill/<name>/`. The `LaravelBoostAssetReader` walks it, renders Blade via the companion's `BladeRenderer`, and hands `Skill[]` to `SyncEngine` via the `injectedVendorSkills` seam.

State this explicitly to the user — don't pretend `boost where` shows the full picture in a wrapper-package context.

## When `boost where` shows nothing

- `boost.php` missing → tell the user to run `vendor/bin/boost install`.
- `.ai/skills/` empty and no allowlisted vendors → no skills to resolve. Suggest `boost tags` to verify intent (the user may have an empty `withTags()` filtering everything out).
- `withTags()` declared but no skill's tag set is a subset → everything filters out. Run `boost tags` and read the "Filtered skills you could enable" roll-up — it shows which tag to add to `withTags()` to unlock each skill.

## Complementary commands

| Question | Command |
|---|---|
| Why was skill X filtered? Which tag would unlock it? | `vendor/bin/boost tags` |
| Is the cache fresh? Which remote sources are pinned vs moving? | `vendor/bin/boost doctor` |
| What would the next sync write/delete? | `vendor/bin/boost sync --check` |
| What collision would the next sync hit? | `vendor/bin/boost sync --check` (errors[] surfaces collisions) |

## Anti-patterns

- **Don't** grep `vendor/*/resources/boost/skills/` to "find" a skill — that misses host overrides, tag filtering, and remote skills entirely.
- **Don't** read `boost.php` and reason about what's enabled — the resolution rules (host wins, vendor declaration order, tag subset) are non-trivial. Run `boost where`.
- **Don't** assume a missing skill means it isn't installed — most "missing" cases are tag-filtered. Check `boost tags` first.
