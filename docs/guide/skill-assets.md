# Skill assets

A skill is not always one file. A skill that runs a script, quotes a long
reference, or ships a template needs those files to travel with it — into every
agent directory, in every project that receives the skill.

Put them in the skill's own directory. `boost sync` copies them verbatim beside
each emitted `SKILL.md`.

```
.ai/skills/codex-review/
├── SKILL.md
├── scripts/
│   └── run-codex-review.mjs
└── references/
    └── prompt-shapes.md
```

After a sync, Claude Code has the whole directory:

```
.claude/skills/codex-review/
├── SKILL.md
├── scripts/
│   └── run-codex-review.mjs
└── references/
    └── prompt-shapes.md
```

The skill body can then point at its own file, and the path resolves the same in
every agent's copy.

## What counts as an asset

Every file under the skill directory that is not the entry file. Subdirectories
keep their structure, so `scripts/run.mjs` stays at `scripts/run.mjs`.

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

## Every source, not just yours

Assets come through all three [skill sources](/guide/skill-sources) — your own
`.ai/skills/`, a vendor package's `resources/boost/skills/`, and a remote GitHub
source. The same collector runs on each, so a skill you install from a package
arrives with its scripts intact.

## Assets are not executable

boost writes an asset as a plain file and does not carry a permission bit across
the sync. A shipped script is not `chmod +x` on the other side.

Write the skill body to call the interpreter rather than the file:

```bash
node scripts/run-codex-review.mjs --uncommitted   # works
./scripts/run-codex-review.mjs --uncommitted      # permission denied
```

## Keep them small

Every asset is copied once per selected agent. A 2 MB reference file with six
agents selected is 12 MB in the repository, regenerated on every sync. Link out
to large material instead of shipping it, and keep in the skill directory what
the agent genuinely reads or runs.

## Ownership

Assets are recorded in the manifest like any other generated file, so they are
reaped when the skill stops shipping, and a file another tool wrote into the same
directory is left alone. See [File ownership](/guide/file-ownership).
