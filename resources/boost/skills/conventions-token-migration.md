---
name: conventions-token-migration
description: Migrate a vendor skill/guideline's $.slot conventions references to render-time boost:conv tokens. Covers the recipe, mode×type placement, footguns, and how to verify with boost where/doctor/validate.
---

# Migrating skills to conventions tokens

Turn a skill/guideline that READS `$.slot` values from the always-loaded
`## Project Conventions` block into one that INLINES them at sync time via
`<!--boost:conv …-->` tokens (boost-core 0.15.0+). Once every skill + guideline a
project syncs is token-based, boost-core drops the standing block entirely — the
value lives next to the instruction instead of as a per-turn context tax.

## When to apply

- Migrating a package's `resources/boost/skills/` or `.ai/` content off `$.slot`
  references onto tokens
- Asked "how do I use boost:conv tokens" / "inline conventions into this skill"
- Reviewing a PR that adds or changes `<!--boost:conv …-->` tokens

## Token shape

```
<!--boost:conv path="github.default_base_branch" mode="inline" fallback="main"-->
```

- `path` (required) — dot-notation slot path. Sub-keys of an open-vocab
  `additionalProperties` map (e.g. `mcp.jira`) are addressable as of **0.16.0**.
- `mode` (required) — `inline` | `bullets` | `yaml` | `json`.
- `fallback` (optional) — prose emitted only when the slot is unset AND the schema
  carries no default. Required for a no-default slot; pointless for a slot the
  schema already defaults (schema-default wins before fallback).

Resolution is three-state by PATH-EXISTENCE: `declared → schema default → fallback`.
A declared `false` / `[]` / `''` is DECLARED, not missing.

## mode × type — pick by the slot's shape

| Slot value shape | Allowed modes | Typical pick |
|---|---|---|
| scalar (string/bool/number) | `inline`, `yaml`, `json` | `inline` |
| scalar list | `inline` (comma-joined), `bullets`, `yaml`, `json` | `inline` for prose-flow, `bullets` for a list |
| map / multi-line | `yaml`, `json` | `yaml` |

A mode that doesn't fit the value's type is a render-class error that fails
`boost sync --check`. A multi-line / map token CANNOT sit in prose — it must go in a
fenced block (next section). If the schema pins a slot's `render` modes, the token's
mode must be one of them.

## Placement — prose vs. fence

- **Prose / inline:** a `mode="inline"` (or `bullets`) token goes straight in the
  sentence: `Write tests in <!--boost:conv path="testing.framework" mode="inline" fallback="your test runner"-->.`
- **Fenced (`yaml`/`json`):** a multi-line token MUST live in a fence whose
  info-string opts in with `boost:conv`:

  ````
  ```yaml boost:conv
  <!--boost:conv path="branches.patterns" mode="yaml"-->
  ```
  ````

  On a clean sync the engine strips the `boost:conv` from the info-string
  (→ ` ```yaml `). A token in a PLAIN fence (no `boost:conv`) or an inline-code
  span is left literal — that's how you show a token as documentation.

## Footguns (learned the hard way)

- **An inline-code-wrapped token stays literal.** `` `<!--boost:conv …-->` `` is
  treated as a documentation example and never resolved. Pull the token OUT of the
  backticks if you want it to inline.
- **`git checkout <file>` nukes in-progress token edits.** Commit incrementally
  while migrating.
- **An errored token is left RAW in the output** (and keeps the block). Don't ship
  a skill whose token references a slot the schema doesn't define — verify first.
- **Map sub-keys need 0.16+.** `path="mcp.jira"` errors as "unknown slot" on
  0.15.x. Floor at `^0.16` if you use them.

## Drop the obsolete scaffolding

Migrating is also a DELETE pass. Remove anything that only existed to read the old
block — it doubles as a drop-gate KEEP-signal that silently prevents the block from
dropping:

- the per-skill `## Project Conventions slots` table and its "reads these slots"
  intro — the schema is the canonical slot doc now;
- prose pointers like "see the Project Conventions section above" / "the
  `branches.patterns` block above";
- the bare `$.slot` references themselves.

A surviving legacy `$.slot` ref, an unresolved token, or a heading-relative pointer
KEEPS the block (fail-toward-keep, by design). The block drops only on positive
proof of full migration.

## Verify before you ship

1. **Audit:** `vendor/bin/boost where --conventions` (+`--json`) — confirm each
   slot's effective source (declared / schema-default / missing) matches what your
   token will resolve.
2. **Both states:** sync once with the slot DECLARED and once UNSET; diff the
   emitted file. Declared → the value; unset → the schema default or your fallback.
   Confirm zero raw `boost:conv` survives a successful sync.
3. **Leak check (0.16.0+):** `vendor/bin/boost doctor` lists any leaked token with
   file:line + cause; `vendor/bin/boost validate --strict` is the CI hard-fail gate.
4. **Floors:** a token-bearing skill REQUIRES the consumer engine to be
   boost-core `^0.15` (a pre-0.15 engine emits the raw token, value lost). Bump the
   package's consumer floor to `^0.15` (or `^0.16` if using map sub-keys) before
   shipping token skills — don't ship them on a constraint that allows < the floor.
