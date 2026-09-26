# Automating the sync

boost-core ships no Composer plugin, so a `composer install` re-sync is opt-in.
Pick the entry point that fits:

| Entry point                        | Use for                                                             |
|------------------------------------|---------------------------------------------------------------------|
| `BoostAutoSync::run`               | `post-install-cmd` / `post-update-cmd` hooks — silent on a no-op    |
| `BoostAutoSync::runWithSummary`    | User-invoked scripts (`composer sync-ai`) — prints a summary always |
| `BoostAutoSync::syncUserScopeOnce` | A globally-installed CLI tool self-syncing its own bundled skills   |

All three honor `BOOST_SKIP_AUTOSYNC=1`.

## Composer hook (consumer project)

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

`run` checks `Event::isDevMode()`, resolves the bin-dir, runs `vendor/bin/boost sync`,
surfaces non-zero exits through Composer's IO, and is **silent on a true no-op**
(`wrote=0, deleted=0`). Output appears only when something changed or errored.
`runWithSummary` prints the one-line success summary on *every* sync, including
the no-ops `run` keeps quiet (useful when debugging "did the hook fire?"). Both
work on Windows + Unix.

> [!IMPORTANT]
> **On Laravel + [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)**,
> use `@php artisan project-boost:sync` instead of `BoostAutoSync::run`. The
> artisan path runs through the Laravel container, which bootstraps `BladeRenderer`
> and delivers laravel/boost's bundled skills to every agent. The bare-CLI path
> bypasses both. See `project-boost-laravel`'s install guide for the canonical
> `scripts` shape.

## CLI tool you publish (self-sync from bin script)

A tool installed with `composer global require` keeps its own bundled skills
current by self-syncing from its bin script:

```php
#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\SanderMuller\BoostCore\Scripts\BoostAutoSync::syncUserScopeOnce(
    packageRoot: dirname(__DIR__),
    packageName: 'your-vendor/your-tool',
);

// ... the tool's own dispatch ...
```

`syncUserScopeOnce()` runs a user-scope sync the first time it sees a given
version, then writes a per-version sentinel so later runs are free.
`syncUserScope()` is the ungated form. Both never throw, so the tool keeps running
even if its sync fails.

## User-scope sync

After `composer global require`-ing skill-bearing packages, run
`vendor/bin/boost sync --scope=user --all` once to user-scope-sync every globally
installed package that ships `resources/boost/skills/`. Each skill lands flat, in
its own folder with a `-user` suffix:

```text
~/.claude/skills/write-spec-user/SKILL.md
~/.cursor/skills/write-spec-user/SKILL.md
…one folder per skill, for each of the 9 agents
```

- **Flat**, because agents discover a skill one level under their skills folder.
  A nested `<vendor>__<package>/<skill>/` folder is invisible to Claude Code.
- **Suffixed**, because an agent prefers a personal skill over a project skill of
  the same name. A user-scope copy has no project conventions filled in, so a
  plain `write-spec` would hide the project's better copy. `write-spec-user` never
  does; both show, and you pick.

References between one package's published skills follow the rename: the
published `interview-user` tells the agent to hand off to `write-spec-user`.
Boost rewrites a whole backticked name (`` `write-spec` ``), a slash command
(`/clarify`), and a relative skill link (`../codex-review/`) in `.md` files. It
leaves plain text, the `description`, and a name the run does not publish alone.

There's no `boost.php` at user scope, so tag filters and the vendor allowlist
(both project-scope controls) don't apply. Removed packages are reaped on the
next `--all` run; see [file-ownership.md](file-ownership.md).

### Choose user-scope skills

By default a package publishes every skill it ships. To publish only some,
create `~/.boost/user-scope.php`:

```php
<?php

return [
    'skills' => [
        // Only these, plus whatever they declare in `metadata.boost-requires`.
        'sandermuller/boost-skills' => ['interview', 'promptimize'],

        // Nothing from this package.
        'acme/noisy-tools' => [],
    ],
];
```

- A package with no entry publishes all its skills.
- A listed skill also publishes the skills of the same package it declares in
  `metadata.boost-requires`, transitively. `interview` above brings
  `clarify-user` and `write-spec-user` with it; the sync prints a note for each.
- A skill you take off the list is reaped on the next sync.
- A name the package does not ship prints a warning; the rest still publishes.
- An empty list publishes no skills. It does not affect the package's
  user-scope guidelines, which the author opts in (below).
- A file that throws, or has the wrong shape, stops every user-scope sync with
  an error. Nothing is published or reaped until you fix it.

Every user-scope sync reads the file: `--scope=user`, `--scope=user --all`, and
the `BoostAutoSync::syncUserScope()` / `syncUserScopeOnce()` hooks a tool calls
on its own. `syncUserScopeOnce()` runs once per tool version, and once more
after each edit to the file.

### Name clashes

The `-user` folders share the agent's skills folder with your own skills and
with other packages. Before it writes anything, a sync checks every target
file: a file boost did not write (your own `SKILL.md`), or a symlink at the
file or its `-user` folder, stops that package's sync with an error that names
the path. Rename or remove it, then sync again.

When two packages publish the same skill name, the first package by name wins
and the second reports the clash, naming the owner. Drop the skill from one of
them in `~/.boost/user-scope.php`. A removed package keeps its claim until
`boost sync --scope=user --all` reaps it; the `--all` run reaps removed packages
before it publishes, so a new owner takes the name over in the same run.

### User-scope guidelines

Guidelines are not published wholesale. A skill costs one description line until
it matches; a guideline is **always-on** — it renders into the agent's guidance
file and sits in context for every session in every repository. Publishing a
package's guidelines wholesale would put `migrations.md` into a Rust repository.

So user scope publishes only the guidelines the **author** marked machine-wide
safe. A package declares eligibility in one of two ways:

```yaml
# resources/boost/guidelines/.boost-user-scope.yaml — paths relative to the
# guidelines directory, one per line
- voice.md
- verification-before-completion.md
- laravel/voice.md
```

A leading `./` is read as the same file. An entry that reaches outside the
guidelines directory — an absolute path, or one with a `..` segment — is
dropped: eligibility decides what enters every session on the machine.

```markdown
<!-- or, for a package whose guidelines carry frontmatter -->
---
name: voice
metadata:
  boost-user-scope: true
---
```

Eligible guidelines render into one boost-owned file per package:

```
~/.claude/boost/<vendor>__<package>.md
```

Boost owns that file wholesale and never writes `~/.claude/CLAUDE.md`. To
activate it, add one import line to your own `~/.claude/CLAUDE.md`:

```markdown
@boost/sandermuller__boost-skills.md
```

Four rules govern the mechanism:

- **Eligibility is not a tag.** `.boost-user-scope.yaml` answers "is this safe
  everywhere on the machine"; `.boost-tags.yaml` answers "which projects does
  this belong to". The two never interact. A guideline can be both — for
  example `voice.md`, tagged `voice` for project scope and eligible for user
  scope. Tags are a project-scope control and have no meaning where there is no
  `boost.php`.
- **An eligible guideline must render token-free.** A
  [conventions token](conventions.md) resolves from `boost.php`, which user
  scope does not have. Boost refuses such a guideline with an error rather than
  publishing a raw token into every session.
- **Both carriers fail closed.** An unreadable sidecar, a sidecar that is not a
  list, or a `metadata.boost-user-scope` that is not `true` all mean "not
  eligible". An eligible guideline that no registered renderer can read (a
  `.blade.php` guideline on the bare-CLI path, say) fails the plan rather than
  publishing guidance without it.
- **The package must also ship skills.** `--scope=user --all` discovers and
  reaps packages by `resources/boost/skills/`, so a guidelines-only package is
  not seen. This is a v1 limit, not a design position; it widens when such a
  package appears.

Withdrawing eligibility reaps the file on the next clean sync, and so does
removing the package. An operator-edited guidance file is preserved, never
reaped — see [file-ownership.md](file-ownership.md).
