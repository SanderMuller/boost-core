# package-boost-php

`sandermuller/package-boost-php` is the family member for **framework-agnostic
Composer package authors**. It bundles the [sync engine](/packages/boost-core/)
with package-author skills, two guidelines, and two `.gitattributes` commands.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/package-boost-php) &middot; [Packagist](https://packagist.org/packages/sandermuller/package-boost-php) &middot; [Releases](https://github.com/SanderMuller/package-boost-php/releases) &middot; [Changelog](https://github.com/SanderMuller/package-boost-php/blob/main/CHANGELOG.md) &middot; [Public API](https://github.com/SanderMuller/package-boost-php/blob/main/PUBLIC_API.md)

Each family package has its own repository and its own release cadence. This site is built from the `boost-core` repository, so a documentation fix goes there, and a code issue goes to the repository above.
:::

Where `laravel/boost` ships Laravel application guidelines, this package ships
package-author CLI infrastructure and skill-authoring tooling. It has no Laravel
dependency.

Writing a **Laravel** package? Install
[`package-boost-laravel`](/packages/package-boost-laravel/) instead. It requires
this package and layers the Laravel-specific skills on top.

## Two CLI commands

Both target `.gitattributes`, the file that controls what ends up in the Composer
archive. Neither overlaps with `laravel/boost`.

| Command | Purpose |
|---|---|
| `vendor/bin/package-boost-php lean` | Check that `.gitattributes` excludes non-shipping paths: tests, fixtures, CI configs, `.ai/`. Wraps `stolt/lean-package-validator` |
| `vendor/bin/package-boost-php gitattributes` | Maintain the `# >>> package-boost (managed) >>>` block. Foreign lines added by other tools are preserved |

## Two guidelines

Always-loaded context for an agent working in a package codebase.

| Guideline | Scope | Tag |
|---|---|---|
| `foundation` | Package-is-not-an-app rules: no `app/` or `.env`, the public API is semver-governed, tests are the spec | — |
| `release-automation` | CHANGELOG-via-CI and release-notes-in-`internal/` conventions | `release-automation` |

## Three skills

Loaded on demand, when the work matches.

| Skill | When it loads | Tag |
|---|---|---|
| `lean-dist` | Keeping the Composer archive lean with `.gitattributes` export-ignore | — |
| `skill-authoring` | Authoring or editing AI skills for the boost family | `boost-extension` |
| `writing-file-emitter` | Implementing a custom `FileEmitter` for boost-core | `boost-extension` |

The `readme`, `release-notes`, and `upgrading` skills moved to
[`boost-skills`](/packages/boost-skills/) under the `release-automation` tag.
Allowlist that vendor to receive them.
