---
name: command-arguments
description: 'How to author argument placeholders in `.ai/commands/<name>.md` so they work across every agent. Triggers when the user asks: "how do command arguments work", "what placeholder syntax should I use", "why does $ARGUMENTS show literally on Cursor", "how do I parameterize a slash command", "does Junie need named arguments", "what does $1 mean on Claude vs OpenCode".'
---

# Authoring argument placeholders in `.ai/commands/<name>.md`

## When to apply

Activate this skill whenever the user is authoring or debugging a `.ai/commands/<name>.md` file and wants to use **arguments** — passing a value the user types after the slash command into the body. The argument-less case (no placeholders, fixed prompt like `/review` or `/ship`) needs no special handling — boost-core copies the body verbatim.

## Canonical placeholder syntax

boost-core defines ONE canonical placeholder syntax for `.ai/commands/<name>.md` sources. The per-agent transpiler converts each placeholder to that agent's native shape on sync, so the operator authors once and gets correct output everywhere.

| Placeholder       | Meaning                                                                                            |
|-------------------|----------------------------------------------------------------------------------------------------|
| `$ARGUMENTS`      | The entire unsplit string of everything the user typed after the command name.                     |
| `$1`, `$2`, …     | One-indexed positional argument. `$1` is the FIRST argument.                                       |
| `$name`           | Named argument. Declare names in the optional frontmatter `arguments:` list for the best emit.    |
| `\$ARGUMENTS`     | Escape — literal `$ARGUMENTS` in the output, no placeholder.                                       |
| `\$1`, `\$name`   | Escape — literal `$1` / `$name` in the output, no placeholder.                                     |

**Frontmatter declaration (optional but recommended for named args):**

```yaml
---
name: jira-triage
description: Triage and label an incoming Jira issue.
arguments:
  - issue
  - priority
---
Triage Jira issue $issue with priority $priority.
Full request: $ARGUMENTS
```

The `arguments:` list drives per-agent named-arg emit. Junie in particular uses it to declare the all-required-named-args contract.

## Per-agent transpilation

boost-core's transpiler converts each canonical placeholder to the agent's native shape. The table below summarizes what each agent emits and where the lossy cases warn:

| Agent       | `$ARGUMENTS`             | `$N` one-indexed     | `$name`                                          | Lossy?                          |
|-------------|--------------------------|----------------------|--------------------------------------------------|---------------------------------|
| Claude Code | `$ARGUMENTS`             | `$(N-1)` (zero-indexed) | `$name`                                       | No                              |
| Cursor      | verbatim                 | verbatim             | verbatim                                         | **Yes** — warns; no syntax      |
| Copilot     | `${input:args}`          | `${input:argN}`      | `${input:name}`                                  | No                              |
| Gemini      | doctor-only              | doctor-only          | doctor-only                                      | n/a — no emit (`boost doctor`)  |
| Junie       | `$args`                  | `$argN` + warn        | `$name` (declare in `arguments:` frontmatter)   | Partial — auto-names positional |
| OpenCode    | `$ARGUMENTS`             | `$N` (native one-indexed) | `$NAME` (uppercased)                        | No                              |
| Amp         | verbatim                 | verbatim             | verbatim                                         | **Yes** — warns; no syntax      |
| Kiro        | `$ARGUMENTS`             | `${N}` (brace form)  | `$name` + warn                                   | Partial — named not native      |
| Codex       | doctor-only              | doctor-only          | doctor-only                                      | n/a — no emit (deprecated)      |

**Warning surface:** lossy transpilations report through `SyncResult::errors` (lenient — sync continues, operator sees the lines). Example:

```
[cursor] deploy: cursor has no placeholder syntax; canonical placeholders emitted verbatim.
[junie] deploy: Junie requires named+required args; positional `$1`, `$2` auto-named to `$arg1`, `$arg2` — declare them in the source frontmatter `arguments:` list so Junie can surface the required-fields prompt.
```

## Strategy

**For maximum cross-agent portability**, prefer `$ARGUMENTS` (unsplit) — it's natively supported on Claude, OpenCode, Kiro, and transpiles cleanly elsewhere. The two no-syntax agents (Cursor, Amp) will warn but emit the body verbatim, giving the operator a clear signal to author Cursor/Amp-specific guidance inline if needed.

**For positional access**, use `$1`, `$2`, … (one-indexed — `$1` IS the first argument). The Claude zero-index quirk is handled by the transpiler; the source stays portable.

**For named arguments**, declare names in `arguments:` frontmatter so Junie's all-required-named-args contract works correctly. The same declaration drives Claude's named-args frontmatter and gives Copilot's `${input:name}` substitution a clean signal.

**When literal `$` content is needed in the body** (shell snippets, dollar amounts, …), escape: `\$ARGUMENTS`, `\$1`, `\$foo`. The transpiler unwraps the backslash and emits the literal `$ARGUMENTS` / `$1` / `$foo` per agent.

## What boost-core does + does not do

**Does:**

- Parses canonical placeholders + escapes from `.ai/commands/<name>.md` source.
- Transpiles per-agent on every sync; warnings surface to `SyncResult::errors`.
- Emits Kiro commands as `.kiro/skills/<name>/SKILL.md` (Kiro's slash-command surface IS its skills directory) — same transpile rules apply.
- Doctor-only manual-path messaging for Gemini (TOML) and Codex (deprecated, personal-only).

**Does NOT:**

- Validate that arguments declared in frontmatter match placeholders in the body (a body using `$3` with only two declarations is the operator's call).
- Substitute literal values at sync time — placeholders are filled by each agent at command-invocation time.
- Discover vendor-shipped commands. `.ai/commands/` is host-authored only — `resources/boost/commands/` in installed Composer packages is NOT scanned. Commands are project-specific far more often than skills are; if you need to share a command across projects, either author it in each project's `.ai/commands/` or distribute it as documentation. (Vendor commands were considered as Phase 4 of the agent-commands-sync spec and explicitly deferred.)

## See also

- `skill-origin-tracing` — "where does my command come from / why is it missing"
- `boost-config-shape` — broader `boost.php` config authoring
- `boost where` — origin-traced output for skills, guidelines, AND commands
