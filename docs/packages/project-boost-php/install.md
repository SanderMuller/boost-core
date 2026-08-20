# Install and first run

```bash
composer require --dev sandermuller/project-boost-php
```

PHP 8.3 or later. `sandermuller/boost-core` comes in transitively.

## First run

```bash
vendor/bin/boost install   # pick agents and allowlist vendors, write boost.php
vendor/bin/boost sync      # fan the skills + guideline out to the selected agents
```

The skills land in your selected agent skill directories (`.claude/skills/`,
`.cursor/skills/`, and so on). The `foundation` guideline merges into each
agent's guidance file (`CLAUDE.md`, `AGENTS.md`, and the rest). Generated
directories are added to `.gitignore` automatically.

Edit `.ai/` only. The fan-out regenerates on every sync.

## Verify

```bash
vendor/bin/boost where     # what ships, and which package supplied it
vendor/bin/boost doctor    # offline health check
```

## Testing the package itself

```bash
composer test
```

The Pest suite is a sanity check on the shipped skill and guideline set: every
skill parses, its `name` matches its filename, and its `description` is not
empty; guidelines carry no frontmatter and open with a Markdown heading.
