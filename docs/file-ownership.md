# File ownership & cleanup

boost-core generates files into your repo and your home directory. This doc
explains what it owns, how it tracks ownership, and when it removes files, so a
sync never silently overwrites something you wrote by hand.

## Config location

The config lives at **`boost.php`** in the repo root, or at **`.config/boost.php`**
if you'd rather keep the root tidy. Pick one. Having both is a hard error
(resolution fails loud rather than guessing). `boost install --config-dir`
scaffolds the `.config/` variant, and every command accepts `--config <path>`.

Source paths default to the project root regardless of where the config lives, so
the two locations are interchangeable. Avoid `__DIR__`-relative paths in the
config, since they break if the file moves.

## Markerless wholesale ownership

The agent-guidance files (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`,
`.github/copilot-instructions.md`) are wholesale boost-owned and carry **no
markers**. boost-core regenerates them in full on every sync from
`.ai/guidelines/` + `boost.php`. Operator-authored guidance belongs in
`.ai/guidelines/` (it's assembled into the file on every sync), not hand-edited
into the emission target.

On the first sync of a legacy marker-bounded file, the markers are stripped and
any genuine out-of-marker content is preserved once below the generated body,
with a warning pointing at `.ai/guidelines/`.

## The ownership manifest

boost-core records what it emits in a gitignored `.boost/manifest.json`
(path → sha + provenance). This is the ownership signal the markerless model
otherwise lacks: with it, sync can safely *converge* a boost-owned guidance file
to empty when all guidance is removed, while **never** touching operator content.
A file you've hand-edited (its sha no longer matches the manifest) is preserved,
not blanked.

The manifest is regenerable emit-state (gitignored, never committed); absent it,
sync falls back to exact pre-manifest behavior. Decisions read the *prior*
manifest and the new one is written only after a fully successful sync, so a
render failure or partial run never corrupts ownership.

When `boost.php` lives under `.config/`, the manifest follows it to
**`.config/boost/manifest.json`** so all boost artifacts group under `.config/`.
Moving the config either direction carries ownership forward: sync reads the
active layout's manifest (falling back to the other layout's copy when absent),
writes it at the active location, and prunes the stale copy (`boost sync --check`
reports that pending one-time cleanup; `boost doctor` shows which location is
active). To move an *existing* config into `.config/`, create the directory first
(`git mv` won't make the parent):

```bash
mkdir -p .config && git mv boost.php .config/boost.php
```

Then run a sync; it relocates the manifest and rewrites the managed `.gitignore`
block for you.

## Lifecycle reap (project scope)

Using the manifest, sync reaps boost-owned files it no longer intends to emit,
replacing the old "delete by hand" step. Two cases:

1. **A dormant `FileEmitter`.** When a vendor dep is removed and its emitter
   returns `null`, the previously-emitted file (e.g. `.mcp.json`) is deleted
   instead of left stale.
2. **A de-selected agent's guidance file.** Drop an agent from `withAgents(...)`
   and its orphaned `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` is removed.

Reaping only ever touches files boost owns. A hand-edited file (sha diverged), a
path turned into a directory, a *disabled* emitter's file (`withDisabledEmitters`
means stop-regenerating, not delete), an emitter that *errored* this run, and a
coincidentally byte-identical pre-existing file are all preserved. A delete that
fails (permissions) retains ownership so the next sync retries.

**Cold-start caveat:** a stale file never recorded by a manifest-era sync (e.g. a
dep removed before upgrading) isn't auto-reaped; remove it once by hand.

`FileEmitter` outputs are recorded in the manifest but are **not** force-added to
`.gitignore`. Whether `.mcp.json` is committed stays your choice, and a reap of a
tracked file shows as a reviewable git deletion. Authors must emit only through
the managed write path to a path they alone own: an emitter returning a reserved
path (a guidance file, `.gitignore`, `.boost/`, `.config/boost/`, an agent
skill/command root, `.ai/`, `resources/boost/`, or a wrapper-claimed path) is
rejected with a diagnostic.

## Empty-assembly guard

Sync never blanks a non-empty guidance file it can't prove it owns: if boost
resolves no guidelines and no conventions, an existing non-empty
`CLAUDE.md` / `AGENTS.md` / `GEMINI.md` that isn't manifest-owned (or is
sha-diverged) is left untouched (an INFO records this) rather than overwritten.
So adopting boost-core in a repo that already has a hand-written `CLAUDE.md` never
wipes it; delete the file manually if you want it empty. (Legacy marker-bounded
files are exempt: they're provably boost-written, so they still converge.)

## Managed `.gitignore`

`boost sync` maintains a managed block in `.gitignore` so generated agent
skill/command **directories** (`.claude/skills/`, `.cursor/skills/`,
`.github/skills/`, …) and the `.boost/` runtime dir stay out of version control.
Edit skills in `.ai/` only; the fan-out regenerates on next sync.

The agent **guidance files** (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`,
`.github/copilot-instructions.md`) are deliberately **not** gitignored: they're
wholesale boost-owned but kept tracked so the output is reviewable in diffs and
present on a fresh clone.

Opt out per project:

```php
return BoostConfig::configure()
    ->withGitignoreManagement(false)
    ->withAgents([...]);
```

Or one-off via env var (useful for CI / ephemeral Docker installs):

```bash
BOOST_SKIP_GITIGNORE=1 composer install
```

## User-scope cleanup-on-remove

User scope (`boost sync --scope=user`) reaps the files of a package you've
`composer global remove`d, the global counterpart of the project-scope reap.
Each user-scope sync records a per-package ownership manifest at
`~/.boost/manifests/<vendor>__<package>.json`. On the next
`boost sync --scope=user --all`, any manifest whose recorded package **install
path no longer exists on disk** has its `~/.{agent}/skills/<slug>/` files reaped
and its manifest deleted.

A still-installed package is keyed on its install path being present, *not* on
whether that particular run discovered it, so running `--all` from a project-local
`vendor/bin/boost` (which can't see the global set) never mass-reaps your global
skills. Reaping is sha-gated (a file you hand-edited is preserved), slug-scoped
(only the package's own dirs), and clean-run-gated (a write error skips reaping).
`--check` reports the pending reap without deleting. A package globally installed
before cleanup-on-remove shipped has no manifest yet, so nothing is reaped until
its first user-scope sync records one.
