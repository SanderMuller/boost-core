# Which package fits

Install one family package. It pulls the engine in for you. Two questions decide
which one:

1. Are you building an **application**, or a **Composer package**?
2. Is it **Laravel**, or not?

| You are building | Install | It ships |
|---|---|---|
| A PHP application (not a package) | [`sandermuller/project-boost-php`](/packages/project-boost-php/) | Application-developer skills — dependency injection, legacy coexistence, and the `foundation` guideline |
| A Laravel application | [`sandermuller/project-boost-laravel`](/packages/project-boost-laravel/) | `laravel/boost` MCP coexistence, nine-agent fan-out, tag filter, remote skills |
| A framework-agnostic Composer package | [`sandermuller/package-boost-php`](/packages/package-boost-php/) | Package-author skills, plus the `lean` and `gitattributes` commands |
| A Laravel package | [`sandermuller/package-boost-laravel`](/packages/package-boost-laravel/) | Laravel package skills, plus the `.mcp.json` emitter |
| Your own skill bundle or tooling | [`sandermuller/boost-core`](/packages/boost-core/) | The bare sync engine. You supply the skills |

Only the last row installs the engine on its own.

## Let an agent pick

Paste this prompt to your coding agent from the repository root. It inspects the
repository, picks the best-fit family member, installs it, configures the agents
and tags, and verifies the result. Nothing installs until it runs
`composer require`:

```text
Install the boost AI-config toolkit in this repository. Read
https://raw.githubusercontent.com/sandermuller/boost-core/main/llms-install.md
and follow it exactly: inspect the repo, pick the single best-fit family member,
install it, and configure boost.php for my stack — the agents I use and matching
tags. Then run the first sync, verify, and tell me what you installed, why, how
it works, and any follow-ups.
```

## How the family stacks

Each wrapper is thin. Every one of them depends on the engine. The Laravel
package member also depends on its framework-agnostic sibling:

```
package-boost-laravel ──▶ package-boost-php ──▶ boost-core   (the engine)
project-boost-laravel ──▶ laravel/boost
                     └──────────────────────▶ boost-core
project-boost-php ────────────────────────────▶ boost-core

boost-skills ─────────────────────────────────▶ boost-core   (optional catalog)
```

`package-boost-laravel` inherits everything `package-boost-php` ships and adds
the Laravel package skills on top, so you never install both halves yourself.
`project-boost-laravel` is not built on `project-boost-php`. It is built on
`laravel/boost`, and it owns the cross-agent fan-out that `laravel/boost` does
not do.

[`boost-skills`](/packages/boost-skills/) is a **catalog** package, not a
dependency of the wrappers. It is Sander's personal skill mix, published as a
worked example of the pattern. Require it and allowlist its vendor if the mix
suits you, or copy the shape and publish your own.

## Can I install more than one?

Install one. Two wrappers in the same repository send overlapping skill sets to
the same agents, and the winner is decided by resolution order rather than by
your intent. If you need skills from another family member, either allowlist that
vendor with `withAllowedVendors()`, or lift the individual skill into your own
`.ai/skills/`.

## `package-boost` is retired

`sandermuller/package-boost` was split into `package-boost-php` (framework
agnostic) and `package-boost-laravel` (Laravel). It is deprecated and receives no
new skills. Replace it with the member that matches your package:

```bash
composer remove --dev sandermuller/package-boost
composer require --dev sandermuller/package-boost-laravel   # or package-boost-php
```
