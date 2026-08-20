# Why boost?

Agent configuration multiplies in two directions at once. Every agent wants its
own file in its own place: `CLAUDE.md` for Claude Code, `AGENTS.md` for Codex and
Amp, `GEMINI.md` for Gemini, `.github/copilot-instructions.md` for Copilot, and a
skills directory per agent on top. Multiply that by the repositories you work in,
and one skill you wrote once now exists as a dozen copies that drift apart.

boost keeps one copy. You author under `.ai/`, and `boost sync` writes every
agent's file from it.

## Already on Laravel? Install `laravel/boost` first

`laravel/boost` is Laravel's own tool, and for a Laravel application it is the
better first install. It runs the MCP server your agent talks to, it exposes the
Laravel documentation API with semantic search, it ships Laravel skills and
guidelines, and it already writes per-agent files for ten agents. None of that is
something this family replaces or does better.

Two things it does not do, by design:

- **It requires Laravel.** Its `composer.json` depends on `illuminate/console`,
  `illuminate/contracts`, `illuminate/routing`, and `illuminate/support`. A
  Symfony application, a plain-PHP service, or a Composer package cannot install
  it.
- **It ships everything it finds.** Skills from your `.ai/skills/`, from its own
  bundle, and from any installed package that publishes boost skills all go to
  every agent you selected. There is no per-project filter.

The second one bites once a repository is not the repository the skill was
written for. A `jira-triage` skill in a repository with no Jira work does not
just sit there unused: its `description` still enters the agent's skill-selection
index, where it costs attention on every unrelated task.

[`project-boost-laravel`](/packages/project-boost-laravel/) exists for exactly
this case. It keeps `laravel/boost` in place, owning the MCP server and the docs
API, and takes over the fan-out so the filtering below applies to the Laravel
skills too. The two run together, and
[a documented command sequence](/guide/laravel-coexistence) keeps them from
overwriting each other's files.

## What this family adds

| | `laravel/boost` | This family |
|---|---|---|
| Framework | Laravel only | Any PHP: Laravel, Symfony, plain PHP, Composer packages |
| Agents written | 10 | 10 (the same set) |
| Bundled Laravel skills, MCP server, docs API | Yes | No, and not planned. Use `laravel/boost` |
| Host `.ai/skills/` | Yes | Yes |
| Composer packages that publish skills | Scanned automatically | Scanned, but only from vendors you allowlist |
| Remote GitHub sources | — | `withRemoteSkills()`, plus a `boost remote` picker |
| Per-project filtering | — | `withTags()`, with a subset rule |
| Skill dependencies | — | `boost-requires`, including rescue of a filtered-out dependency |
| Project-specific values in vendor skills | — | Project Conventions slots |
| Slash-command fan-out | — | `.ai/commands/`, transpiled per agent |
| Skills outside a project | Project only, every write goes under the app root | `boost sync --scope=user`, for globally-installed CLI tools |
| Where a skill came from | — | `boost where`, with host / vendor / remote / shadow origin |
| Drift check for CI | — | `boost sync --check`, offline and deterministic |
| Health check | — | `boost doctor` |

The agent count is not the argument: both write the same ten. The argument is
the rows with a dash in the first column, and the first row for anyone not on
Laravel.

## What boost does not do

- **It ships no skills.** The engine is a sync engine. Skills come from you, from
  a wrapper package, or from a catalog such as
  [`boost-skills`](/packages/boost-skills/).
- **It does not run an MCP server** and has no documentation API. In a Laravel
  project those stay `laravel/boost`'s job.
- **It does not judge skill content.** Sync checks that a skill parses and that
  its dependencies exist. Whether the skill gives good advice is on the author.
- **It does not install or configure your agents.** It writes files into the
  places those agents already read.
- **It runs nothing on its own.** There is no Composer plugin. A sync happens
  when you run it, or when you wire the
  [hook](/guide/automating-sync) yourself.

## When you do not need this

One repository, one agent, a handful of instructions you rarely change: write
`CLAUDE.md` by hand. There is nothing to fan out and nothing to drift.

The cost of boost is a config file and a sync step. That pays for itself at the
second agent or the second repository, and not really before.
