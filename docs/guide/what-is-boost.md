# What boost does

You write skills, guidelines, and slash commands once, in a `.ai/` directory at
the root of your repository. `boost sync` publishes them to every AI agent you
selected: Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, and
Amp. You never maintain a per-agent copy.

![What boost-core does](/overview.jpg)

## What you get

- **One `.ai/` source, nine agents.** Add an agent to `withAgents()` and its
  files appear on the next sync.
- **Skills distributed as Composer packages.** Any package that ships
  `resources/boost/skills/` becomes a skill source once you allowlist its vendor.
  Author a team's skill set once, then `composer require` it everywhere.
- **Per-project scoping.** [Tag filtering](/guide/tags-and-dependencies) keeps a
  `jira-triage` skill out of repositories with no Jira work, including out of the
  agent's skill-selection index, where every unwanted `description` costs
  attention.
- **Skills that depend on skills.** `metadata.boost-requires` makes a hand-off
  ("then run `code-review`") ship as a unit instead of breaking silently.
- **Skills straight from GitHub.** `boost remote <owner>/<repo>` reads a
  repository and lets you pick from a checklist.
- **Nothing hand-written gets clobbered.** boost tracks what it owns in a
  manifest. Adopting it in a repository that already has a `CLAUDE.md` does not
  wipe that file.

It runs on any PHP project: Laravel, Symfony, plain PHP, or a Composer package.

## The engine and the family

`sandermuller/boost-core` is the engine. It contains the sync, the CLI, and the
agent emitters. It ships no skills of its own.

Around the engine sits a family of thin wrapper packages. Each one bundles the
engine with a curated skill set for one kind of work: an application or a
package, on Laravel or on plain PHP. On top of any of them sits
[`boost-skills`](/packages/boost-skills/), an optional catalog of ready-made
skills.

You install **one** wrapper. It pulls in the engine for you.
[Which package fits](/guide/which-package) is the picker.

![The boost family](/overview_family.jpg)

## Coming from `laravel/boost`?

`laravel/boost` is a Laravel-only tool that gives an agent an MCP server and the
Laravel documentation API. boost-core is the framework-free sibling that fans
configuration out across agents. They are complementary, and
[they coexist](/guide/laravel-coexistence).

|  | `laravel/boost` | boost-core |
|---|---|---|
| Framework scope | Laravel only | Any PHP (Laravel, Symfony, plain PHP, packages) |
| Skill sources | Bundled + `.ai/skills/` | `.ai/skills/` + Composer packages + `withRemoteSkills()` + vendor allowlist |
| Tag filtering | None | `withTags()` subset rule |
| Skill dependencies | None | `metadata.boost-requires`, with rescue |
| Remote skill sources | None | `withRemoteSkills()` — GitHub bundles and path imports |
| User-scope sync | None | `boost sync --scope=user` for globally-installed CLI tools |
| Origin tracing | None | `boost where`, `boost where --diff=<name>` |
| Health check | None | `boost doctor`, `boost doctor --check-versions` |
| `.ai/commands/` fan-out | None | Per-agent argument transpilation across 7 targets |
| Project Conventions | None | JSONSchema slot fill-in via `boost validate` / `boost slots` |

The MCP server and the Laravel documentation API stay `laravel/boost`'s domain,
so boost-core defers to them in Laravel projects. See
[`project-boost-laravel`](/packages/project-boost-laravel/) for that setup.
