# Commands

`.ai/commands/*.md` holds reusable prompt templates: the slash-command files
agents surface in their palette. `boost sync` fans each out to the seven agents
with a command surface:

| Agent       | Command target                                 |
|-------------|------------------------------------------------|
| Claude Code | `.claude/commands/`                            |
| Cursor      | `.cursor/commands/`                            |
| Copilot     | `.github/prompts/` (as `<name>.prompt.md`)     |
| Junie       | `.junie/commands/`                             |
| OpenCode    | `.opencode/commands/`                          |
| Amp         | `.agents/commands/`                            |
| Kiro        | `.kiro/skills/<name>/SKILL.md` (slash-command) |

Codex, Gemini and Antigravity have no committable command target boost-core can
write into: Codex's prompts are deprecated and personal-only, Gemini uses TOML,
and Antigravity publishes no command directory at all. When `.ai/commands/` is
populated and one of those agents is selected, `boost doctor` prints the manual
authoring path so the gap isn't silent. Override the source dir
with `->withCommandsPath(...)`.

## Argument placeholders

Author once using the canonical syntax:

| Syntax        | Meaning                                                          |
|---------------|------------------------------------------------------------------|
| `$ARGUMENTS`  | Everything the user typed, unsplit                               |
| `$1`, `$2`, … | Positional arguments                                             |
| `$name`       | Named argument, optionally declared in frontmatter `arguments:`  |
| `\$`          | A literal `$`                                                    |

On sync, boost-core converts each to the agent's native shape: Claude's
`$0`-indexed positionals, Copilot's `${input:…}`, and so on. Cursor and Amp have
no placeholder support, so their output is verbatim and sync warns about it. The
bundled `command-arguments` skill documents the full conversion table.
