# boost-core

`sandermuller/boost-core` is the engine every family member rides on. It holds
the sync, the CLI, the agent emitters, the manifest, and the plugin contracts. It
ships no skills of its own.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/boost-core) &middot; [Packagist](https://packagist.org/packages/sandermuller/boost-core) &middot; [Releases](https://github.com/SanderMuller/boost-core/releases) &middot; [Changelog](https://github.com/SanderMuller/boost-core/blob/main/CHANGELOG.md) &middot; [Public API](https://github.com/SanderMuller/boost-core/blob/main/PUBLIC_API.md)

This site is built from the `boost-core` repository, so a documentation fix and a code issue both belong here.
:::

Most people never install it directly. A family wrapper pulls it in, and the
[guide](/guide/what-is-boost) documents the engine's behavior as it appears
through any of them.

## When to install it directly

- You publish **your own skill catalog** and want the engine without another
  package's opinions on top.
- You are building **tooling on the engine**: a custom `FileEmitter`, a custom
  `SkillRenderer`, or a CLI that self-syncs.
- Your project is **none of the four roles** the wrappers cover, such as a non-PHP
  package with a PHP toolchain, for instance.

Everything else is better served by [picking a
wrapper](/guide/which-package).

## What the engine gives you

- Ten agent emitters: Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro,
  OpenCode, Amp, and Antigravity.
- Three skill sources (host, Composer vendor, and remote GitHub) resolved side
  by side. See [Skill sources](/guide/skill-sources).
- Tag filtering and skill dependencies. See
  [Tags and dependencies](/guide/tags-and-dependencies).
- Project Conventions: a JSONSchema slot vocabulary a vendor declares and a
  consumer fills. See [Project Conventions](/guide/conventions).
- A manifest-backed ownership model, so a sync never silently overwrites
  hand-written content. See [File ownership](/guide/file-ownership).
- Command fan-out with per-agent argument transpilation. See
  [Commands](/guide/commands).
- A pluggable render step for template-flavored skills. See
  [Skill rendering](/guide/skill-rendering).

## The public surface

The semver promise covers the config authoring API, the CLI, the `BoostAutoSync`
Composer hooks, and the plugin contracts. Everything marked `@internal`, which is the whole
engine, and all regenerable on-disk state may change in any release. See
[Versioning and stability](/reference/versioning).
