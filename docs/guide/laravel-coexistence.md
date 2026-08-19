# Coexisting with `laravel/boost`

`laravel/boost` and this package both write to your agent guidance files
(`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, …). They do it in two different ways,
and that difference is the one real footgun. This guide explains the canonical
command sequence, why a bare `vendor/bin/boost sync` can lose content, and how
`project-boost:reconcile` makes a takeover safe.

## The canonical sequence

```
boost:install            # once — laravel/boost wires MCP + seeds its guidelines
project-boost:reconcile  # once — capture that seed safely (only if you hand-edit guidance)
project-boost:sync       # from then on — re-renders + fans out on every run
```

- **`php artisan boost:install`** (laravel/boost) — run once. It wires the MCP
  client config and seeds its bundled guidelines into your agent files.
- **`php artisan project-boost:reconcile`** (this package) — run once after that
  first install, or when migrating an existing laravel/boost project onto the
  wrapper. It captures anything a sync would otherwise clobber. Skip it if you
  have never hand-edited a guidance file.
- **`php artisan project-boost:sync`** (this package) — the ongoing command. Wire
  it into your composer `post-install-cmd` / `post-update-cmd`.

> **Never run a bare `vendor/bin/boost sync` on a wrapper project.** The bare CLI
> bypasses this package's laravel/boost injection, so it assembles a *smaller*
> guidance set and wholesale-overwrites your files with it. Always go through
> `php artisan project-boost:sync`.

## Why the footgun exists

The two tools write guidance differently:

| Tool | How it writes the guidance file |
|------|---------------------------------|
| `laravel/boost` | **Preserve-region.** Wraps its guidelines in a `<laravel-boost-guidelines>…</laravel-boost-guidelines>` marker and `preg_replace`s only that block. Content outside the marker is left alone. |
| `boost-core` (via this package) | **Wholesale + markerless.** The file is regenerated in full on every sync. boost-core does not recognise laravel/boost's marker. |

So when boost-core syncs, it replaces the *entire* file. That is correct and
intended for boost-owned content — but a file that laravel/boost seeded (or that
you hand-edited) holds content boost-core did not author:

- The `<laravel-boost-guidelines>` block itself — **safe under
  `project-boost:sync`**, because this package re-injects laravel/boost's
  guidelines, so the sync re-derives equivalent content. (A *bare*
  `vendor/bin/boost sync` does not inject them, which is why it loses them.)
- **Hand-authored content outside the marker** — your own notes, team
  conventions, edits. Nothing re-derives this. A sync drops it.

That second category is the data loss `project-boost:reconcile` exists to
prevent.

## What `project-boost:reconcile` does

```bash
php artisan project-boost:reconcile          # interactive: shows the plan, asks, captures, syncs
php artisan project-boost:reconcile --dry-run # show the plan only, write nothing
php artisan project-boost:reconcile --no-sync # capture + back up, but don't sync yet
php artisan project-boost:reconcile --force   # skip the confirmation prompt
```

For each agent guidance file the project's configured agents target, it:

1. **Detects** laravel/boost-seeded files by their `<laravel-boost-guidelines>`
   marker (the same signal `vendor/bin/boost doctor` uses) — a false-positive-free
   check that never flags a cleanly boost-owned file.
2. **Splits** the marker body (laravel/boost's guidelines, re-derivable by sync)
   from the hand-authored residual outside it (not re-derivable — at risk).
3. **Backs up** every at-risk file verbatim to `.boost-reconcile/`, so nothing
   is ever lost even if you change your mind.
4. **Captures** the deduplicated residual into `.ai/guidelines/reconciled.md`, so
   boost-core composes it back into every agent file on the next sync — turning
   your one-off hand-edits into durable, fanned-out guidance.
5. **Runs `project-boost:sync`** to regenerate the files (now including the
   captured content), unless `--no-sync`.

After reconciling, review `.ai/guidelines/reconciled.md` — split or rename it
into properly named guideline files if you like — and delete `.boost-reconcile/`
once you are happy. (Both are safe to add to `.gitignore`.)

> **Durable home for hand-written guidance.** Edit `.ai/guidelines/`, never the
> generated `CLAUDE.md` / `AGENTS.md` directly. boost-core composes everything
> under `.ai/guidelines/` into every agent file on each sync; edits to the
> generated files are overwritten.

## Division of labor

See the [project-boost-laravel overview](/packages/project-boost-laravel/) for
the full who-owns-what breakdown. The short version:
laravel/boost owns MCP + Laravel docs + its bundled guidelines; this package owns
the cross-agent fan-out of skills + guidelines via `project-boost:sync`.

## `boost.json` and the `herd link` trigger

`herd link` runs `php artisan boost:update` by itself whenever `vendor/laravel/boost`
is present — Herd's bundled valet CLI does it right after linking a site, with no
prompt. `boost:update` re-runs laravel/boost's installer: it rewrites the guidance
files inside its `<laravel-boost-guidelines>` marker and reinstalls its skill
directories into the agent skill folders. Neither is coordinated with
`project-boost:sync`, so the next sync reports a guidance takeover and the skill
directories sit alongside (and unfiltered by) the ones this package fans out.

The clean stop is to retire `boost.json`, and a successful `project-boost:sync`
now does that for you — archiving it rather than deleting it, and only after its
agent list has been adopted into your own config.

Why it is safe:

- `Laravel\Boost\Support\Config` — the class behind `boost.json` — is referenced only
  by `boost:install` and `boost:update`.
- The MCP server does not read it. `boost:mcp` is `Artisan::call('mcp:start laravel-boost')`
  against a server `BoostServiceProvider::boot()` registers unconditionally.
- This package does not read it either: every sync re-derives laravel/boost's
  guidelines and skills from `vendor/laravel/boost/.ai/`.
- `config/boost.php` — laravel/boost's *Laravel* config file (MCP toggles, browser
  logs, rules) — is a different file and is untouched.

Why it works: `boost:update` returns early when the file is missing or has no agents
(`! $config->isValid() || empty($config->getAgents())`), so the Herd trigger becomes
a no-op.

Guards, so it is never a blunt delete:

| Situation | What happens |
|---|---|
| `boost.json` has laravel/boost's keys, agents already adopted | archived to `.boost/boost.json.retired` (`.config/boost/…` on that layout) |
| it lists an agent your `boost.php` does not | kept; the sync names the agent and points at `vendor/bin/boost install`, whose picker pre-selects it |
| it lists an agent boost-core has no case for (`zed`, `pi`, …) | ignored for the gate — it could never be adopted |
| `boost.json` has none of its keys | kept — it belongs to another tool |
| `boost.json` is a symlink | never unlinked; a warning names it |
| the sync failed | kept — laravel/boost's own path stays the fallback |
| gitignore management is off | kept — creating a state dir would leave an untracked directory behind |
| the state dir is a symlink | kept — `rename()` would follow it outside the project |
| an archive already exists | identical content → source dropped; different content → archived alongside as `boost.json.retired-<hash>` |
| `--dry-run` | reported as `would-archive`, nothing moved |
| `--keep-boost-json` | step skipped entirely |

Run `php artisan boost:install` to bring the file (and laravel/boost's own writers) back.

## `.ai/rules` stays laravel/boost's

`.ai/rules/` is laravel/boost's channel, not this package's: its own guideline tells
agents to open `.ai/rules/index.md` (a glob → rule-file map) before editing, and its
`record-rule` MCP tool writes there. boost-core has no `.ai/rules` pipeline at all.

Nothing needs copying, because the rules are *path-scoped and read on demand* — the
opposite of `.ai/guidelines/`, which is inlined wholesale into every guidance file.
Inlining them would duplicate content agents already fetch and throw away the glob
scoping that makes them cheap.

What matters is that the *instruction* reaches every agent, not just the one talking
to laravel/boost's MCP server. It does: `project-boost:sync` injects laravel/boost's
own `boost/core` guideline into the assembly, and that fragment carries the directive
(laravel/boost 2.5+; 2.4 has no rules feature). A test pins the injection so it can't
break silently.

A project WITHOUT this wrapper gets no laravel/boost guidance at all, so its agents
are never told `.ai/rules` exists — `boost doctor` says so when the directory is there.
