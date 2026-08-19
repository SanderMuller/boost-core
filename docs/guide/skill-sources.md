# Skill sources

Skills come from three places. All three resolve on the same
`composer install` / `composer update` lifecycle and are fanned out side by side.

## 1. Host

Your project's own `.ai/skills/`. Nothing to configure. A directory with a
`SKILL.md` in it ships.

```bash
vendor/bin/boost new skill my-skill --description="What it does."
```

A host skill with the same name as a vendor skill **shadows** it. `boost where`
marks the shadow, and `boost where --diff=<name>` prints the difference between
your copy and the vendor's.

## 2. Vendor packages

Any Composer package that ships `resources/boost/skills/` is a skill source,
whether it uses the flat layout (`<name>.md`) or the nested one
(`<name>/SKILL.md`). Allowlist its vendor to pick it up:

```php
return BoostConfig::configure()
    ->withAllowedVendors(['vendor/package'])
    ->withAgents([Agent::CLAUDE_CODE]);
```

This is how a team distributes one curated skill set across many repositories:
author once in a package, then allowlist everywhere.
[`sandermuller/boost-skills`](/packages/boost-skills/) is a published example of
the pattern.

The allowlist is deliberate. An installed package does not push skills into your
repository until you name its vendor. Run `vendor/bin/boost scan` after
installing a package that publishes skills, and it re-runs the picker for you.

## 3. Remote sources {#remote-sources}

GitHub repositories that ship `.skill` release bundles or skill subdirectories,
declared with `withRemoteSkills()` or picked interactively:

```console
$ vendor/bin/boost remote mattpocock/skills
```

`boost remote` resolves the reference, works out whether the repository publishes
release assets or skill directories, reads each skill's frontmatter, and shows a
checklist with descriptions, tags, and any clash with a skill you already
receive. Checked skills land in `withRemoteSkills()`; unchecked ones are removed.
It also pulls in any dependency the same repository publishes, and offers to
declare the tags a picked skill needs to survive your `withTags()` filter.

See [Remote skills](/guide/remote-skills) for hand-written entries, the `--ref`
and `--mode` flags, the cache, offline behavior, rate limits, the trust model,
and publishing your own source.

## Which source won

```bash
vendor/bin/boost where              # every skill, guideline, and command, with its origin
vendor/bin/boost where --diff=name  # host override versus the vendor copy
```

Origins are reported as host, vendor, remote, or shadow, so a skill that is not
what you expected can be traced back to the package that supplied it.
