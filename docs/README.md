# Documentation

The rendered site is at **<https://sandermuller.github.io/boost-core/>**. It
covers the whole boost family, not only this package.

This folder is the source. Everything below renders on the site; the links here
point at the Markdown so they work on GitHub too.

## Guide

Shared behavior, written once for every family member.

- [Why boost?](guide/why-boost.md) — what it adds over `laravel/boost`, and when you need neither
- [What boost does](guide/what-is-boost.md) — one `.ai/` source, ten agents, and the family
- [Which package fits](guide/which-package.md) — pick one member from your role
- [Installation](guide/installation.md) — require, scaffold, first sync
- [How sync works](guide/how-sync-works.md) — what you author, and what each agent receives
- [Skill sources](guide/skill-sources.md) — host, vendor packages, remote GitHub repos
- [Remote skills](guide/remote-skills.md) — `withRemoteSkills()`, the cache, the trust model
- [Commands](guide/commands.md) — command fan-out targets and argument placeholders
- [Skill rendering](guide/skill-rendering.md) — `SkillRenderer` dispatch, failure modes, authoring
- [Tags and dependencies](guide/tags-and-dependencies.md) — filtering, `boost-requires`, rescue
- [Project Conventions](guide/conventions.md) — the slot schema and the token syntax
- [File ownership](guide/file-ownership.md) — the manifest, reaping, `.config/` layout
- [Automating the sync](guide/automating-sync.md) — Composer hooks, self-syncing CLI tools, user scope
- [Coexistence with `laravel/boost`](guide/laravel-coexistence.md) — the canonical sequence and the data-loss seam

## Packages

One section per family member.

Each package area has an overview, then the pages that differ from the guide.

- **project-boost-php** — PHP applications:
  [overview](packages/project-boost-php/index.md),
  [install](packages/project-boost-php/install.md),
  [configuration](packages/project-boost-php/configuration.md)
- **project-boost-laravel** — Laravel applications:
  [overview](packages/project-boost-laravel/index.md),
  [install](packages/project-boost-laravel/install.md),
  [configuration](packages/project-boost-laravel/configuration.md)
- **package-boost-php** — framework-agnostic Composer packages:
  [overview](packages/package-boost-php/index.md),
  [install](packages/package-boost-php/install.md),
  [configuration](packages/package-boost-php/configuration.md)
- **package-boost-laravel** — Laravel packages:
  [overview](packages/package-boost-laravel/index.md),
  [install](packages/package-boost-laravel/install.md),
  [configuration](packages/package-boost-laravel/configuration.md)
- **boost-skills** — the published skill catalog:
  [overview](packages/boost-skills/index.md),
  [catalog](packages/boost-skills/catalog.md)
- **boost-core** — the bare engine:
  [overview](packages/boost-core/index.md),
  [install](packages/boost-core/install.md),
  [publishing a skill package](packages/boost-core/publishing-skills.md)

## Reference

- [CLI reference](reference/cli.md)
- [Configuration reference](reference/configuration.md)
- [Environment variables](reference/environment.md)
- [Versioning and stability](reference/versioning.md)

## Working on the site

```bash
cd docs
npm ci
npm run dev     # local preview
npm run build   # what CI runs — a dead internal link fails the build
```

`.vitepress/pages.ts` is the single source of truth for the navigation, the
sidebars, and the generated `llms.txt`. Add a page there and to this index when
you add a Markdown file.
