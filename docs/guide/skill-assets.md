# Skill assets

A skill is not always one file. A skill that runs a script, quotes a long
reference, or ships a template needs those files to travel with it, into every
agent directory, in every project that receives the skill.

Put them in the skill's own directory. `boost sync` copies them verbatim beside
each emitted `SKILL.md`.

```
.ai/skills/codex-review/
├── SKILL.md
└── scripts/
    └── run-codex-review.mjs
```

After a sync, every selected agent has the whole directory:

```
.claude/skills/codex-review/
├── SKILL.md
└── scripts/
    └── run-codex-review.mjs
```

The skill body can then point at its own file, and the path resolves the same in
every agent's copy.

## What counts as an asset

An asset is every file under the skill directory that is not the entry file.
Subdirectories keep their structure, so `scripts/run.mjs` stays at `scripts/run.mjs`.

Four rules decide the edge cases:

- **The entry file is not an asset.** A top-level `SKILL.*` file is the skill
  itself. That also protects a file parked beside it: `SKILL.md.license` is
  treated as an entry candidate, not shipped as an asset.
- **A deeper `SKILL.*` file is an asset.** `examples/SKILL.md` is example
  content, not a second skill.
- **Hidden files are skipped**, along with backup and editor temp files, matching
  how the loader filters skill sources.
- **A flat skill has no assets.** `skills/my-skill.md` has no directory of its
  own, so there is nothing to collect. Use the nested layout when a skill needs
  to ship files.

## Assets from every source

Assets come through all three [skill sources](/guide/skill-sources): your own
`.ai/skills/`, a vendor package's `resources/boost/skills/`, and a remote GitHub
source. The same collector runs on each, so a skill you install from a package
arrives with its scripts intact.

## Assets are not executable

boost never sets an executable bit. The writer creates every file with the
default mode and
calls `chmod` nowhere, so a script arrives at `644` even when the source copy
is executable.

Write the skill body to call the interpreter rather than the file:

```bash
node scripts/run-codex-review.mjs --uncommitted   # works
./scripts/run-codex-review.mjs --uncommitted      # permission denied
```

## Keep them small

Every asset is copied once per selected agent. A 2 MB reference file with six
agents selected is 12 MB in the repository, regenerated on every sync. Link out
to large material instead of shipping it, and keep in the skill directory what
the agent reads or runs.

## Ownership

The manifest records each asset under its own per-agent path, the same as any
other generated file:

```json
".claude/skills/codex-review/scripts/run-codex-review.mjs": { ... }
```

So an asset is reaped when its skill stops shipping, and a file another tool
wrote into the same directory is left alone. See
[File ownership](/guide/file-ownership).
