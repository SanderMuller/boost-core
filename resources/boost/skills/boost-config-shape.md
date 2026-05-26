---
name: boost-config-shape
description: Author a boost.php config file. Covers the with* methods, when each matters, and what NOT to put in there.
---

# boost.php config shape

## When to apply

- Editing a project's `boost.php` by hand
- Asked "how do I configure boost-core?"
- Reviewing a PR that touches `boost.php`

## The fluent shape

```php
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
    ->withAllowedVendors([
        'sandermuller/project-boost',
    ])
    ->withDisabledEmitters([])
    ->withSkillsPath(__DIR__ . '/.ai/skills')
    ->withGuidelinesPath(__DIR__ . '/.ai/guidelines');
```

## Method semantics

- `withAgents([])` — which of the 9 agents to fan out to. Empty array = skip
  fan-out (`boost:sync` becomes a no-op). Order doesn't matter.
- `withAllowedVendors([])` — Composer package names whose
  `resources/boost/{skills,guidelines}/` boost-core may read from. Empty
  = host-only. Non-allowlisted vendors are silently ignored even if they
  publish. The `boost:install` / `boost:scan` picker pre-checks
  first-party packages as a convenience, but that is picker UX only —
  `boost:sync` gates purely on this exact list. A hand-written
  `boost.php` with an empty array syncs zero vendor skills, first-party
  or not.
- `withDisabledEmitters([])` — FQCNs of FileEmitter classes to skip even
  if their vendor is allowlisted. Use for opting out of optional emissions
  (e.g. you want skills from `package-boost-laravel` but not `.mcp.json`).
- `withTags(Tag::Php, 'jira', ...)` — variadic; the project's tags.
  A vendor skill *or guideline* ships only when every tag in its
  `metadata.boost-tags` frontmatter is declared here
  (`itemTags ⊆ projectTags`). Untagged skills and guidelines always ship. Accepts `Tag` enum cases (autocomplete) or raw
  strings (the vocabulary is open). Omit it entirely to receive every
  untagged skill and filter out every tagged one. `vendor/bin/boost tags`
  lists the tags installed skills declare and which ones a missing tag
  would unlock.
- `withExcludedSkills([])` — `vendor/package:skill-name` entries to drop
  regardless of tags. A per-skill deny-list for vendor skills you don't
  want without shipping a shadowing host copy.
- `withExcludedGuidelines([])` — `vendor/package:guideline-name` entries
  to drop regardless of tags. The guideline counterpart of
  `withExcludedSkills()` — and the only filter for a vendor guideline
  shipped without `metadata.boost-tags`, since untagged guidelines always
  ship and tag-filtering cannot reach them.
- `withSkillsPath(...)` / `withGuidelinesPath(...)` — host-authored content
  locations. Default to `<project-root>/.ai/skills` and
  `<project-root>/.ai/guidelines`. Override only if your project uses a
  non-conventional layout.
- `withRemoteSkills([RemoteSkillSource::githubBundle(...), RemoteSkillSource::githubPath(...)])` —
  declarative non-Composer skill sources. `githubBundle()` pulls `.skill`
  ZIP release assets; `githubPath()` extracts a subdir of a repo at a
  ref. First sync hits the network and caches on disk; later syncs are
  offline-fast. Set `BOOST_GITHUB_TOKEN` to lift anonymous 60/h to
  5000/h. `BOOST_REMOTE_STRICT=1` aborts on any remote failure (default
  is warn-and-skip). `boost doctor` reports per-source cache state and
  flags moving refs.
- `withSkillRenderers([new BladeRenderer, ...])` — register renderer
  plugins for template-flavored skill bodies (`SKILL.blade.php`,
  `SKILL.twig`, …). Longest-extension-first match; the implicit
  `PassthroughRenderer` always handles `.md`. The contract is
  `@experimental` — pin to an exact boost-core version if building
  against it. Reference consumer:
  `sandermuller/project-boost-laravel`'s `BladeRenderer`.
  `BOOST_RENDER_STRICT=1` escalates renderer exceptions (separate from
  `BOOST_REMOTE_STRICT`).
- `withDisabledRenderers([FQCN::class])` — FQCNs of registered renderers
  to skip. The implicit passthrough is re-appended after the deny-list
  so `.md` always renders.

## Configuring filtering — discover, then suggest

Setting up or reviewing `withAllowedVendors()` / `withTags()` / `withExcludedSkills()` / `withExcludedGuidelines()`? Don't guess — discover, then propose:

1. **Discover vendors.** `vendor/bin/boost doctor` (read-only) lists installed packages that publish skills/guidelines, split into allowlisted vs discovered-but-not-allowlisted. Decide which vendors the project should trust and add them to `withAllowedVendors()`. (`vendor/bin/boost scan` does the same opt-in **interactively but rewrites `boost.php`** — it's the apply step, not a read-only probe.)
2. **Discover tags.** Once the relevant vendors are allowlisted, `vendor/bin/boost tags` lists every tag their skills declare and, per missing tag, which skills it would unlock. `boost tags` only sees *allowlisted* vendors — a vendor's tags stay invisible until step 1 admits it, so do step 1 first.
3. **Suggest tags from project context.** Match those tags against what the project *is* — read `composer.json` (Laravel app? framework-agnostic package?), the issue tracker, the CI host — and propose the `withTags()` entries that unlock relevant skills, each with a one-line reason. Only suggest tags an installed skill actually declares; an undeclared tag unlocks nothing.
4. **Suggest individual excludes.** Tags filter in bulk; when an allowlisted vendor ships one specific item the project doesn't want — irrelevant to the stack, or redundant with how the project already works — and no tag cleanly singles it out, propose an exclude for exactly that item: `withExcludedSkills(['vendor/package:skill-name'])` for a skill, `withExcludedGuidelines(['vendor/package:guideline-name'])` for a guideline. The `vendor/package:name` keys are the ones `boost tags` and `boost doctor` already print. The guideline deny-list especially matters for a vendor guideline shipped *without* `metadata.boost-tags` — untagged guidelines always ship, so tag-filtering can't reach them and the exclude is the only lever. Reserve excludes for genuine one-offs — broad filtering is the tags' job.

Present every suggestion with its reasoning — the maintainer decides. Declaring a tag is opt-in (it only ever *adds* skills); an exclude only ever *removes* one.

**Shortcut for interactive setups:** `vendor/bin/boost install` runs the agent + vendor + tag pickers in sequence and persists the choices into `boost.php` via AST. The tag picker shows each discovered tag with an "unlocks N skill/guideline" hint and pre-checks already-declared tags. Use it when the operator's at a terminal and willing to step through the choices; use the "discover, then suggest" flow above when you're proposing changes for review.

## What NOT to put here

- **Environment branching.** `if (env('CI')) { ... }` works, but
  `boost:install` and `boost:scan` AST-edit this file and refuse on
  unexpected shape. Keep the file a single straight return.
- **External `require` calls.** Same reason — AST writer can't preserve
  side effects.
- **Header docblocks above `return`.** PHP-Parser's pretty-printer
  strips them on `boost:install` / `boost:scan`. Inline them in the
  starter template `boost:install` generates on first run instead.

## Anti-patterns

- **Catch-all agents list.** Adding all 9 agents to a project you don't
  actually use is just file churn. Pick the ones you actually run.
- **Allowlisting third-party vendors without inspecting them.** Skills
  from allowlisted vendors influence AI behavior in your project — same
  trust boundary as any code dep. Use `vendor/bin/boost doctor` to see what
  would be published before allowlisting.
- **Hand-editing during interactive commands.** If you run
  `vendor/bin/boost install` and then hand-edit `boost.php` before the
  next `boost sync`, your hand-edits win. But the AST writer's next
  invocation may not preserve formatting choices — commit before
  hand-editing so you can diff.

## See also

- The starter `boost.php` template generated by `vendor/bin/boost install` on first run
- `writing-file-emitter` skill for emitter authoring
- `skill-origin-tracing` skill — routes to `boost where` for "where does skill X come from / why is it missing" questions
