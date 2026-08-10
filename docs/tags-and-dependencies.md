# Tag filtering and skill dependencies

Two features decide *which* skills a project receives, and they interact, so this
page covers both. The [README](../README.md#tag-filtering) states the basic rule
of each; here is the full reference.

## Tag filtering

**The rule:** a vendor skill ships only when *every* tag in its `boost-tags` is
among the project's `withTags()` (`skillTags ⊆ projectTags`). An untagged skill
always ships, so the feature is inert until skills and projects opt in.

One exception: a tag-dropped skill that a shipping skill declares in its
`boost-requires` is pulled back in. See [Rescue](#rescue) below.

`withExcludedSkills()` drops a specific `vendor/package:skill-name` regardless of
tags.

### Guidelines filter the same way

Vendor **guidelines** are tagged either by `metadata.boost-tags` or a sidecar
`resources/boost/guidelines/.boost-tags.yaml` manifest. The sidecar exists for
guidelines that must stay frontmatter-free to remain readable by `laravel/boost`.
`withExcludedGuidelines(['acme/pack:unwanted-guideline'])` is the guideline-side
deny-list.

### The tag vocabulary is open

The `Tag` enum is a convenience, not a closed set; any string is a valid tag.

- `vendor/bin/boost tags` lists every tag installed skills and guidelines declare,
  which of them your `withTags()` filters out, and what to add to receive them.
- `boost install`'s interactive picker offers the same choices.
- When sync drops tagged skills because `withTags()` is empty, it prints a
  one-line nudge pointing at `boost tags`.

> [!WARNING]
> Adding a tag to an **already-shipped** skill is consumer-breaking: every project
> that hasn't declared that tag loses the skill. Treat it as a breaking change.

### Tracing what shipped and why

`boost where` traces where every resolved skill, guideline, and command comes
from (host / vendor / remote), with host-override shadowing annotated inline.
`boost where --diff=<name>` prints a unified diff between a host override and the
vendor copy it shadows.

## Skill dependencies

**The rule:** whenever a skill ships, every name in its `boost-requires` ships
too.

```yaml
---
name: interview
description: Requirements-gathering flow that hands off to write-spec.
metadata:
  boost-requires: "write-spec"
---
```

### Rescue

A dependency that tag filtering would have dropped is *rescued*: the author's
"this skill is broken without it" outranks topic scoping, so it ships anyway.
Sync reports every rescue as an INFO diagnostic, and rescue is transitive, so a
rescued skill's own requires ship as well.

### The details

- **Bare names, not `vendor/package:` keys.** Dependencies bind to the name:
  a host `.ai/skills/` override of the dep satisfies it, and any provider can.
- **`withExcludedSkills()` wins.** An exclude removes that provider's copy
  from consideration; if no other provider holds the name, sync warns and the
  dependent ships degraded. Rescue never overrides an explicit deny.
- **Missing deps warn, never fail.** A require naming a skill that exists
  nowhere is a sync warning + `boost doctor` finding, not an error.
- **Cycles are legal.** `a ⇄ b` simply co-ships.
- **Malformed `boost-requires`** (not a string) warns at sync and is an
  **error** in `boost validate --strict`. Unlike `boost-tags`, it does not
  fail closed, because requires gate completeness, not scoping.

### Authoring guidance

Declare only hard hand-offs, meaning flows that *invoke* the other skill.
Conditional references ("where the project has quality-check skills synced,
delegate to them") and routing notes ("NOT for X, use Y") must stay undeclared,
or rescue drags unrelated tooling into projects that scoped it out via tags.
`boost validate -v` flags requires that cross a tag boundary, so the choice stays
deliberate.
