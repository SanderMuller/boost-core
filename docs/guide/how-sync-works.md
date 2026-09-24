# How sync works

You author three kinds of content under `.ai/`. `boost sync` fans each one out to
every agent you selected in `withAgents(...)`:

| You write in | What it is | `boost sync` fans it out to |
|---|---|---|
| `.ai/skills/` | Agent Skills (`<name>/SKILL.md`) | `.{agent}/skills/<name>/SKILL.md` per agent |
| `.ai/skills/<name>/scripts/` and so on | Skill asset siblings (scripts, references) | Copied verbatim beside each emitted `SKILL.md` |
| `.ai/guidelines/` | Always-loaded guidance | `AGENTS.md`, `GEMINI.md`, the Copilot file |
| `.ai/commands/` | Slash-command prompt templates | Per-agent command directories — see [Commands](/guide/commands) |

Skills and commands land in gitignored per-agent directories. The guidance files
stay tracked, so the output is reviewable in a diff. [File
ownership](/guide/file-ownership) explains why the two are treated differently.

## The three stages

1. **Collect.** Skills, guidelines, and commands are gathered from every
   [source](/guide/skill-sources): your own `.ai/`, allowlisted Composer
   packages, and declared remote repositories.
2. **Filter.** [Tags](/guide/tags-and-dependencies) drop what this project did
   not ask for. Skill dependencies rescue anything a shipped skill declares it
   needs. Exclusions apply last.
3. **Emit.** Each selected agent's emitter writes the files in that agent's own
   layout, and the manifest records every path boost now owns.

## Skills

A skill is a directory with a `SKILL.md` inside it. The frontmatter carries the
name, the description an agent reads when it decides whether to load the skill,
and boost's own metadata:

```yaml
---
name: code-review
description: Structured review of recent code changes.
metadata:
  boost-tags: "php"
  boost-requires: "write-spec"
---
```

Files next to `SKILL.md` (`scripts/`, `references/`, anything else) are copied
verbatim beside every emitted copy, so a skill that shells out to its own script
keeps working after the fan-out. See [Skill assets](/guide/skill-assets) for the
rules and the one gotcha: a shipped script arrives without an executable bit.

## Guidelines

`.ai/guidelines/*.md` is always-loaded guidance. Sync assembles the files in
order into one guidance document per agent, and writes it to that agent's
conventional path: `AGENTS.md`, `GEMINI.md`, and the Copilot instructions
file. Claude Code shares `AGENTS.md` with Codex and the other agents that read
it.

::: warning Claude Code and `CLAUDE.md`
Claude Code reads `AGENTS.md` only when the project has no `CLAUDE.md`,
`.claude/CLAUDE.md`, or `CLAUDE.local.md` (Claude Code v2.1.277 or later). If
one of those files exists, add an `@AGENTS.md` line to it (`@../AGENTS.md` in `.claude/CLAUDE.md`), or move its content
into `.ai/guidelines/` and delete it. `boost sync` and `boost doctor` warn when
such a file blocks `AGENTS.md`. A `CLAUDE.md` that boost wrote before 1.12 is
removed on the next sync.
:::

These targets are **wholesale boost-owned and markerless**. Author guidance in
`.ai/guidelines/`, never by hand-editing the target. A hand edit survives, but
it also stops boost from regenerating that file.

## Commands

`.ai/commands/*.md` holds reusable prompt templates: the slash commands an agent
surfaces in its palette. Sync fans them out to the seven agents that have a
command surface and rewrites the argument placeholders into each agent's own
syntax. See [Commands](/guide/commands).

## Dry runs and drift

```bash
vendor/bin/boost sync --check
```

`--check` reports what a real sync would change and writes nothing. It is
offline and deterministic, which makes it the command to gate CI on: a pull
request that edits `.ai/` but forgets to re-sync fails the check.
