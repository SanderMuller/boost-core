# Subagents

A subagent is a Claude Code definition file that runs a pass in its own context.
`.ai/subagents/*.md` holds yours; a package ships its own under
`resources/boost/subagents/`. `boost sync` writes both into `.claude/agents/`.

The fresh context is the point. A review pass that judges a diff as somebody
else's code cannot do that inline, where the same agent that wrote the code
applies the rules. Shipping the pass as a subagent keeps that property; flattening
it into a skill does not.

Subagents are a Claude Code feature. Every other agent target receives nothing,
silently — `boost doctor` reports the count once so the gap isn't a surprise.

## Where files land

| Source                                          | Emitted to                                    |
|-------------------------------------------------|-----------------------------------------------|
| `.ai/subagents/<name>.md` (host)                 | `.claude/agents/boost/host/<name>.md`         |
| `resources/boost/subagents/<name>.md` (a package) | `.claude/agents/boost/<vendor__package>/<name>.md` |

Everything boost writes goes under **`.claude/agents/boost/`**, and that subtree
is the only thing added to the managed `.gitignore` block. The directory above it
is yours: hand-written definitions at `.claude/agents/<name>.md` are never
touched, never tracked in the manifest, and never removed by a sync. Adopt a
package's subagents one at a time — nothing has to move first.

Override the host source dir with `->withSubagentsPath(...)`, and a package's
published dir with `extra.boost.subagents` in its `composer.json`. Both source
dirs are walked recursively, so organise them however you like — the emitted
path comes from the `name`, never from the source layout.

A file with no `name` in its frontmatter is skipped, with a warning naming it.
Claude Code ignores such a file too, treating it as documentation kept beside
your agents, so emitting it would ship something nothing reads.

Because identity is the `name` and not the filename, two of your own files can
claim one name. Sync keeps the first and warns which one it ignored. A **package**
shipping two files with one name is an error rather than a warning: only the
package author can fix it, and the message names both files.

## Identity is the `name`, never the path

Claude Code scans `.claude/agents/` recursively and takes a subagent's identity
**only** from its `name` frontmatter field. Two facts follow, and both matter:

- The `boost/<vendor__package>/` folders namespace **nothing**. They record where
  a file came from, and that is all. (Plugins work differently — a subfolder
  there does become part of the identifier. That rule does not apply here.)
- Two files declaring the same `name` collide, wherever they sit. Claude Code
  loads one of them, chosen by filesystem read order, with no documented
  precedence.

So `boost sync` warns when a name it emits is also declared by a file it does not
own, and `boost doctor` lists every such overlap it finds on disk — including two
of your own files that collide with each other, which is a bug boost can see
before Claude Code silently picks a side.

boost never resolves a collision. At least one file is normally yours, and
resolving it would mean deleting your work. **Rename or remove one side.** When
you adopt a package's version of a subagent you already wrote, delete your local
copy — the warning is the feature working, not a fault.

Act on the warning rather than filing it. In an observed collision between a
hand-written `.claude/agents/<name>.md` and an emitted
`.claude/agents/boost/host/<name>.md`, the **emitted** file was the one that
loaded. That is one filesystem ordering and not a rule, but it is the bad
direction: a package's subagent can quietly outrank the file you wrote.

One more precedence note: a project subagent outranks your personal
`~/.claude/agents/` file of the same name, and you get no signal from Claude Code
when that happens.

## Filtering and dependencies

Subagents follow the skill rules exactly:

- `metadata.boost-tags` filters them, with the same subset rule.
- `withExcludedSkills(['acme/pack:reviewer'])` denies one by name. Subagents share
  the skill deny-list rather than adding a second config surface.
- A host subagent shadows a vendor one of the same name. The shadow is reported by
  `boost where` and `boost doctor`, never silent.
- Two packages publishing one name is a hard error, resolvable with `--force` or
  by writing your own copy.

A skill declares a hard dependency on a subagent with the `subagent:` prefix:

```yaml
---
name: evaluate
description: Self-directed eval loop.
metadata:
  boost-requires: "code-review subagent:simplification-auditor"
---
```

Bare names still mean skills. The prefix exists so a skill and a subagent may
share a name without the demand being ambiguous. A demanded subagent that tag
filtering would have dropped is rescued, exactly like a skill, and sync reports
the rescue.

An unsatisfiable demand warns and never fails: sync says whether the name was
**excluded** by this project or **missing** from every installed package, and the
skill still ships. A subagent nothing declares is fine — an operator can dispatch
one directly, so an unreferenced subagent is not an orphan and nothing warns
about it.

> [!WARNING]
> A package that declares a `subagent:` dependency must floor `boost-core` at the
> release that added it. An older boost-core reads the whole token as a skill
> name, so every consumer below that floor sees a spurious missing-dependency
> warning. No engine change can fix that retroactively.

The **files** need no floor. An older boost-core never looks for
`resources/boost/subagents/`, so it ignores them. A package can therefore ship
its subagents today and add the `subagent:` requires when it raises its floor —
there is no reason to hold the whole feature back.

## Writing a skill that dispatches one

A skill that dispatches a subagent should keep working for a consumer who does
not have it — and should say what that costs. State the fallback **and what it
loses**, twice: once in the skill, so an author knows the fallback is weaker,
and once in the **run's own output**, so the person reading the result knows
which version produced it. A weaker pass that does not announce itself is read
as a clean one.

```markdown
Dispatch the `simplification-auditor` subagent when it is present. Otherwise run
the cut pass inline, and note in the output that it ran without a fresh context:
the same rules applied by the agent that wrote the code, which is the reviewer
the rules exist to distrust.
```

The inline pass is not a smaller version of the dispatched one. Say so, or a
consumer without the subagent assumes parity and trusts a weaker result.

## Limits

User scope is not covered. A globally installed package ships its skills to
`~/.claude/skills/`, but no subagents — `.claude/agents/boost/` is project-scope
only.
