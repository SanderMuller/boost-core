---
name: boost-command-surfaces
description: 'Pick the right `boost` command when laravel/boost and boost-core are both installed — they share the `boost` name and are not interchangeable. Triggers when about to run or explain: "boost sync", "boost install", "boost update", "boost:install", "boost:update", "boost:mcp", "project-boost:sync", "vendor/bin/boost", "herd link", "regenerate CLAUDE.md", "update the guidelines", "add a skill", "why did my skills disappear", "why is CLAUDE.md different".'
---

# Which `boost` command is which

## When to apply

Activate before running, recommending, or explaining ANY command with `boost` in its name in a project where both packages are installed, and whenever guidance files or skills changed unexpectedly. Two different packages own commands called `boost` and running the wrong one produces the wrong file in the wrong place.

Check what is installed first:

```bash
composer show laravel/boost sandermuller/boost-core sandermuller/project-boost-laravel 2>/dev/null
```

## The two command families

| Entry point | Package | Owns |
|---|---|---|
| `php artisan boost:*` — `boost:install`, `boost:update`, `boost:mcp`, `boost:add-skill`, `boost:list-skills` | `laravel/boost` | Its MCP server, its bundled Laravel guidelines, and its own copies of its bundled skills |
| `vendor/bin/boost <verb>` — `install`, `sync`, `doctor`, `where`, `tags`, `validate`, `slots`, `paths`, `new`, `scan` | `sandermuller/boost-core` | The wholesale guidance-file assembly (`CLAUDE.md` / `AGENTS.md` / …) and the skill fan-out to every configured agent |
| `php artisan project-boost:sync` | `sandermuller/project-boost-laravel` | The coexistence path: injects laravel/boost's skills + guidelines INTO the boost-core assembly |

Note the shape difference: boost-core's commands are internally named `boost:install`, `boost:sync`, … but the standalone binary strips the prefix. `vendor/bin/boost sync` — never `vendor/bin/boost boost:sync`, and never `php artisan boost:sync` (that command does not exist).

## Which sync to run

- **Wrapper installed (`sandermuller/project-boost-laravel`)** → `php artisan project-boost:sync`. It is the only path that composes laravel/boost's content together with the host's. Bare `vendor/bin/boost sync` composes a thinner set and reports a guidance takeover.
- **No wrapper** → `vendor/bin/boost sync`. laravel/boost's bundled guidelines are then NOT part of the assembly; its `<laravel-boost-guidelines>` block in a guidance file is replaced by boost-core's wholesale write (it warns first).
- Run `vendor/bin/boost doctor` when unsure — it prints the division of labour for the project it is run in.

## Editing rules

- Never hand-edit `CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, or anything under `.claude/skills/`, `.claude/commands/`. boost-core regenerates them wholesale from `.ai/` and `boost.php`.
- Author skills and guidelines under `.ai/skills/` and `.ai/guidelines/`, then sync.
- `.ai/skills/<name>` is contested: laravel/boost treats that path as its own canonical copy for any skill listed in `boost.json`, and overwrites the directory with vendor content. Do not give a host-authored skill a name that appears in `boost.json`'s `skills` array.
- `.ai/rules/` belongs to laravel/boost — its own guideline tells agents to read `.ai/rules/index.md` before editing, and its `record-rule` MCP tool writes there. boost-core never reads or composes those rule files; on a wrapper project the *instruction* to read them does reach every agent (it rides laravel/boost's core guideline through `project-boost:sync`, on laravel/boost 2.5+), so follow it when the directory exists. Rules stay path-scoped and read on demand; guidance that every agent must always follow goes in `.ai/guidelines/` instead.
- Three similarly named config files, all different: `boost.json` (laravel/boost's install state), `config/boost.php` (laravel/boost's Laravel config — MCP toggles, browser logs), and root `boost.php` / `.config/boost/boost.php` (boost-core's config). Editing one never affects another.
- `boost.json` is read by `boost:install` and `boost:update` only. The MCP server does not read it, and neither does boost-core or the wrapper. On a wrapper project it is optional: deleting it makes `boost:update` — and therefore the `herd link` trigger — refuse to run, without affecting MCP or the injected laravel/boost content. From project-boost-laravel 1.3, a successful `php artisan project-boost:sync` retires it for you — archived to `.boost/boost.json.retired`, and only once every agent it lists is declared in `boost.php` (`vendor/bin/boost install` pre-selects them). `--keep-boost-json` opts out; `php artisan boost:install` brings it back. Do not delete it on a project with no wrapper: nothing would then deliver laravel/boost's bundled guidelines and skills.

## Why files appear, disappear, or revert

- `herd link` runs `php artisan boost:update` on its own whenever `vendor/laravel/boost` is present. So linking a site re-seeds laravel/boost's guidelines and skills without anyone typing a boost command. If guidance or skills changed after a `herd link`, that is the cause — re-run the project's sync (`project-boost:sync`, or `vendor/bin/boost sync`) to converge.
- laravel/boost installs its bundled skills as real directories inside the agent skill directories boost-core also manages. boost-core preserves them — it deletes only what its own manifest records — and `boost doctor` lists them. They reach only that one agent; boost-core's fan-out does not carry them to Codex / Cursor / Copilot.
- To find out which source produced a specific skill, guideline, or command, use the `skill-origin-tracing` skill (`vendor/bin/boost where`).

## Anti-patterns

- **Don't** run `php artisan boost:install` to "regenerate the guidelines" in a boost-core project — it writes laravel/boost's own marker block, which the next boost-core sync takes over.
- **Don't** run `vendor/bin/boost sync` on a wrapper project as a shortcut. Use `php artisan project-boost:sync`.
- **Don't** delete a skill directory to "clean up" — find its owner first with `vendor/bin/boost where` and `vendor/bin/boost doctor`.
- **Don't** infer the entry point from the words in a task ("run boost sync"). Check which packages are installed, then pick from the table above.
