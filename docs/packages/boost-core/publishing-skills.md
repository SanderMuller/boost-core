# Publishing a skill package

Any Composer package can become a skill source. Ship the files in the right place
and every consumer picks them up by allowlisting your vendor. This is how a team
distributes one curated skill set across many repositories.

## The layout

```
resources/boost/
├── skills/
│   ├── my-skill.md           # flat layout — the whole skill in one file
│   └── bigger-skill/         # nested layout
│       ├── SKILL.md
│       └── scripts/          # asset siblings, copied verbatim beside every emitted SKILL.md
├── guidelines/
│   ├── house-style.md
│   └── .boost-tags.yaml      # optional: tags for the guidelines
└── conventions-schema.json   # optional: Project Conventions slots you declare
```

`resources/boost/` is the contract. Nothing outside it is scanned.

Both skill layouts are supported, and they can sit side by side:

- **Flat** — `skills/<name>.md`. The skill is the file, and the filename is the
  name. Use it when the skill is only prose.
- **Nested** — `skills/<name>/SKILL.md`. The directory is the name. Any other
  file in that directory is an asset and is copied verbatim next to every
  emitted `SKILL.md`, so a skill that shells out to its own script keeps working
  after the fan-out. A `SKILL.*` file deeper than the top level is an asset, not
  a second skill.

## A skill

`SKILL.md` carries YAML frontmatter. `name` must match the directory name, and
`description` is what an agent reads when it decides whether to load the skill:

```yaml
---
name: my-skill
description: One sentence naming the trigger, so an agent knows when to load it.
metadata:
  boost-tags: "php github"
  boost-requires: "other-skill"
---

# My skill

The body an agent reads once the skill loads.
```

- `boost-tags` — the project must declare **every** tag listed here, or the skill
  does not ship. Leave it off and the skill always ships.
- `boost-requires` — hard hand-offs. Whenever this skill ships, each named skill
  ships too, even one the consumer's tags would have filtered out.

Both are covered in [Tags and dependencies](/guide/tags-and-dependencies).

::: warning
Adding a tag to a skill that already ships is **consumer-breaking**. Every
project that has not declared the tag loses the skill. Treat it as a breaking
change, and prefer publishing a new skill over re-tagging an established one.
:::

## A guideline

Guidelines are plain Markdown with **no** frontmatter, opening directly on a
heading. That keeps them readable by `laravel/boost`, which has no guideline
frontmatter parser. Tags therefore live in a sidecar manifest:

```yaml
# resources/boost/guidelines/.boost-tags.yaml
# Each entry maps a guideline filename to a space-delimited tag string.
# A guideline not listed here is untagged and ships everywhere.
database-safety.md: "database"
javascript.md: "frontend"
```

## Declaring conventions slots

If your skills need project-specific values — an issue-tracker key, a branch
pattern, a test runner — do not bake them into the skill body. A consumer would
have to shadow the whole skill to change one word. Declare slots instead, in
`resources/boost/conventions-schema.json`, and let the consumer fill them in
`boost.php`. See [Project Conventions](/guide/conventions).

## What a consumer does

```bash
composer require --dev your-vendor/your-skills
vendor/bin/boost scan     # re-run the allowlist picker
```

```php
->withAllowedVendors(['your-vendor/your-skills'])
```

Nothing ships until the vendor is allowlisted. That is deliberate: installing a
package never pushes files into someone's repository on its own.

## Keep the archive lean

Skills are `require-dev` content for your consumers, but `resources/boost/` must
ship in the Composer archive. Check your `.gitattributes` export-ignore rules
before tagging — [`package-boost-php`](/packages/package-boost-php/) provides the
`lean` command for exactly this.
