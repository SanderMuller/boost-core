# Project Conventions

Vendor skills often need project-specific context: a Jira project key, a branch
naming pattern, a test runner name. Without a way to inject it, every consumer
shadows the skill just to change one line, and the maintenance debt explodes.

Project Conventions is a JSONSchema-based slot fill-in that closes that gap:

- A **vendor** declares the slots it needs in `resources/boost/conventions-schema.json`.
- A **consumer** fills them in `boost.php` via `->withConventions([...])`.
- `boost sync` validates the values and makes them available to skills and guidelines.

`boost.php` is the single source of truth. The relevant frozen formats (the
`boost:conv` token, the schema-version handshake, the `render` annotation) are
pinned in [`PUBLIC_API.md`](../PUBLIC_API.md#textual--wire-formats).

## Declaring values

```php
// boost.php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withConventions([
        'jira' => ['project_key' => 'HPB'],
        'github' => ['default_base_branch' => 'develop'],
    ]);
```

Diagnostics route through `SyncResult::diagnostics` (a lenient channel that never
fails sync) and render after the primary `boost sync` / `boost where` output.

## Two ways to consume a slot

### 1. Inline tokens (preferred)

A skill or guideline references a slot with a render-time token, and `boost sync`
resolves the value INTO the emitted file, so the value lives next to the
instruction, loaded on demand with the skill:

```
<!--boost:conv path="jira.project_key" mode="inline"-->
```

Resolution order is `declared → schema default → inline fallback`, keyed by path
existence (a declared `false` / `[]` counts as declared, not missing). `mode` is
one of `inline` (scalars + comma-joined scalar lists), `bullets`, `yaml`, or
`json`. A type×mode mismatch, an unknown slot, or an unset slot with no default
and no fallback is a render-class error that fails `boost sync --check`.

#### Paired visible-default form (1.2.1+)

A bare `<!--boost:conv …-->` token resolves only under boost-core. An engine with
no resolver — notably `laravel/boost`, which installs a package's `SKILL.md` and
preserves HTML comments verbatim — leaves it inert, so an inline token reads as a
**word gap** and its fallback stays hidden inside the comment.

The paired form closes that gap by wrapping a visible default between an open and
an end marker:

```
Run <!--boost:conv path="testing.runner" mode="inline"-->Pest<!--boost:conv:end--> to verify.
```

- boost-core replaces the whole span (open comment → `<!--boost:conv:end-->`) with
  the resolved value; the visible default doubles as the inline fallback (an
  explicit `fallback=` still wins).
- A resolver-less engine leaves both comments inert, so the visible default reads
  as ordinary prose — `Run Pest to verify.` — no gap.

The ` ```boost:conv ` fence takes the same `<!--boost:conv:end-->` marker and
buffers its body as one block, so a multi-line span resolves whole. Paired spans
resolve before bare tokens, so an open comment is never consumed as a stray
unpaired token; inline-code and plain-fence examples stay literal; an orphan end
marker is inert and keeps the Project Conventions block. Prefer this form for any
token that may ship to a consumer using `laravel/boost`.

### 2. The `## Project Conventions` block (legacy)

`boost sync` also renders declared values into a markerless `## Project Conventions`
section in `CLAUDE.md` (always, even if Claude Code isn't in `withAgents(...)`).
This block is **kept** whenever any live skill or guidance still needs it (a legacy
`$.slot` reference, an unresolved token, or prose pointing at "the Project
Conventions section") and **drops** only on positive proof of full migration to
tokens. A project with no tokens renders the block unchanged.

Inspect the effective resolved set any time with `boost where --conventions`
(add `--json` for a machine-readable shape).

## Observability

Three surfaces catch problems:

| Surface | What it reports |
|---|---|
| `boost sync` | Inline `file:line` warnings for leaked tokens; INFO per block keep-reason (under `-v`) |
| `boost doctor` | Lists every token leak with its cause; a "Project Conventions block" section naming why the block is kept |
| `boost validate --strict` | Turns each leaked token into an error → **hard-fails CI** |

**Leaked tokens.** A token that doesn't resolve leaves a raw `<!--boost:conv …-->`
comment in the emitted file, so the agent reads the literal token instead of the
value. The usual cause is a token-bearing vendor skill synced by a consumer still
on boost-core < 0.15 (the old engine copies the token verbatim). Detection is
prose-scoped: a token inside a plain code fence or inline-code span is an
intentional literal and is never flagged.

**Keep-reasons.** When the block is kept, boost-core names the single artifact
still holding it open (a skill or guidance file) and its cause: a legacy
`$.<root>` ref, an unresolved token, a prose pointer, or `(no migration yet)` for
a pure-conventions project that hasn't adopted tokens. Run `boost where --conventions`
against a tree you expect to be fully migrated to find the one ref still pinning it.

**Legacy `$.<root>` refs.** A pre-token `$.slot` reference (e.g. `$.testing.runner`)
is detected but never resolved, so it emits literally and dangles for every
non-Claude agent (the block is CLAUDE.md-only). `boost validate` surfaces each
distinct ref as a **warning** (so it does not fail `--strict`, since a ref may be
mid-migration), pointing at the first file it appears in.

**Canonical CI recipe:** run `boost sync` (or `composer install`), then
`boost validate --strict` over the post-sync emitted set.

## Migrating vendor skills to tokens

Package authors moving a skill off `$.slot` references onto tokens should follow
the `conventions-token-migration` skill (shipped in `resources/boost/skills/`).
It's the canonical recipe for the `mode`×type placement rules, the footguns
(inline-code-wrapped tokens stay literal; an errored token ships raw), dropping
the obsolete slot-table scaffolding, and the verify-before-ship workflow.

**Floor rule:** a token-bearing skill requires the consumer engine at `^0.15`
(a pre-0.15 engine emits the raw token, value lost), or `^0.16` if it uses
open-vocab map sub-keys like `mcp.jira`. Bump the package's consumer floor before
shipping token skills.

## Migrating from 0.8.x marker conventions

A project still carrying a `boost-core:conventions:*` marker block migrates the
YAML into `boost.php` with `vendor/bin/boost convert-conventions`. Run it *before*
upgrading to 0.12.0, while the markers still exist. After a 0.12.0 sync has
stripped the markers, copy any preserved conventions YAML into
`->withConventions([...])` by hand. `boost.php` is canonical thereafter.

For schema authors, see the spec in `internal/specs/conventions-schema.md`
(gitignored, branch-local).
