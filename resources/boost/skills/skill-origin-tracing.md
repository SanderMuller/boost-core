---
name: skill-origin-tracing
description: 'Trace where a skill comes from or why one is missing/unexpected. Triggers when the user asks: "why is skill X present", "why is skill Y missing", "where does skill Z come from", "which package ships X", "is X from host or vendor", "did host shadow X", "what skills will boost sync", "why did boost sync remove a skill", "what version of X is being used".'
---

# Trace a skill's origin

## When to apply

Activate this skill whenever the user wants to know **where a skill came from, why it's present, why it's missing, or which copy is winning when two sources publish the same name**. Don't guess from the codebase — use `boost where`.

Common phrasings to watch for:

- "Why is `<name>` present / missing / not in my .claude/skills?"
- "Where does `<name>` come from?"
- "Which package ships `<name>`?"
- "Is `<name>` from my `.ai/skills/` or from a vendor package?"
- "Did my host override `<name>`?"
- "What skills will `boost sync` write?"
- "Why did `boost sync` remove `<name>`?"
- "Which version of `<name>` is being used?"

## Primary command — `boost where`

Run from the project root:

```bash
vendor/bin/boost where
```

Output groups every resolved skill by origin:

- **`.ai/skills/ (host)`** — host-authored skills. A `(shadows <vendor>)` annotation flags host skills that override an allowlisted-vendor skill of the same name.
- **`<vendor/package>`** — a Composer-allowlisted vendor publishing skills via `resources/boost/skills/`.
- **`<vendor/package>` from a `RemoteSkillSource`** — non-Composer source declared via `withRemoteSkills(...)` in `boost.php` (GitHub `.skill` bundles, repo subdirs).

Skills that DO NOT appear in `boost where` output were dropped by the tag filter, by `withExcludedSkills`, or by a malformed `metadata.boost-tags` ("fail closed"). Cross-check with `boost tags` (see below) to see what was filtered and why.

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
