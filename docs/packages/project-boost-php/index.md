# project-boost-php

`sandermuller/project-boost-php` is the family member for **PHP application
developers**, on any framework or none. It ships two framework-agnostic skills and
a `foundation` guideline that frames the codebase as an application rather than a
package. It rides the [sync engine](/packages/boost-core/) and ships no code of
its own.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/project-boost-php) &middot; [Packagist](https://packagist.org/packages/sandermuller/project-boost-php) &middot; [Releases](https://github.com/SanderMuller/project-boost-php/releases) &middot; [Changelog](https://github.com/SanderMuller/project-boost-php/blob/main/CHANGELOG.md) &middot; [Public API](https://github.com/SanderMuller/project-boost-php/blob/main/PUBLIC_API.md)

Each family package has its own repository and its own release cadence. This site is built from the `boost-core` repository, so a documentation fix goes there, and a code issue goes to the repository above.
:::

Building a **Laravel** application? Install
[`project-boost-laravel`](/packages/project-boost-laravel/) instead. It layers
`laravel/boost` coexistence on the same nine-agent fan-out.

## Two skills

Universally-applicable PHP practices, not tied to any architecture.

| Skill | Triggers when |
|---|---|
| `dependency-injection` | Constructor injection, container hygiene, avoiding service locators |
| `legacy-coexistence` | Adding modern PHP (typed properties, `readonly`, enums) to a 7.x codebase incrementally |

## One guideline

`foundation` — framework-agnostic application-developer framing: what an
application codebase is, how its edges form its real contract, and how to work in
it. Always shipped, no tag required.

Architecture-specific guidance (DDD layering, repositories, domain modeling)
shipped through the 0.x line and was dropped at 1.0, to keep the default
framework agnostic. Copy any of it into your own `.ai/skills/<name>/SKILL.md`, where a
host copy shadows a vendor skill, or drop a shipped skill with
`->withExcludedSkills(['sandermuller/project-boost-php:<name>'])`.

## How it compares to `laravel/boost`

|  | `laravel/boost` | `project-boost-php` |
|---|---|---|
| Framework scope | Laravel only | Any PHP — Symfony, plain PHP, framework agnostic |
| Skill set | Laravel runtime guidelines (Eloquent, Blade) | Framework-agnostic application practices, plus `foundation` |
| Agent reach | Laravel applications only | Non-Laravel applications too, on the same agent set |
| Tags, remote skills, vendor allowlist | — | Through the engine |
| MCP server and Laravel docs API | Yes | Not in scope — use `laravel/boost` directly |

Both can sit in the same Laravel application through
[`project-boost-laravel`](/packages/project-boost-laravel/), the family member
built for that combination.
