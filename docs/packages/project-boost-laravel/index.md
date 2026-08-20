# project-boost-laravel

`sandermuller/project-boost-laravel` is the family member for **Laravel
applications**. It is the companion to `laravel/boost`, not a replacement:
`laravel/boost` stays the MCP server and the Laravel docs API, and this package
owns the agent-file fan-out (skills, guidelines, remote skills, and tag
filtering) across all ten agents.

::: info Where this package lives
[GitHub](https://github.com/SanderMuller/project-boost-laravel) &middot; [Packagist](https://packagist.org/packages/sandermuller/project-boost-laravel) &middot; [Releases](https://github.com/SanderMuller/project-boost-laravel/releases) &middot; [Changelog](https://github.com/SanderMuller/project-boost-laravel/blob/main/CHANGELOG.md) &middot; [Public API](https://github.com/SanderMuller/project-boost-laravel/blob/main/PUBLIC_API.md)

Each family package has its own repository and its own release cadence. This site is built from the `boost-core` repository, so a documentation fix goes there, and a code issue goes to the repository above.
:::

## What it adds on top of `laravel/boost`

|  | `laravel/boost` alone | With `project-boost-laravel` |
|---|---|---|
| MCP server (`boost:mcp`) | Yes | Unchanged |
| Laravel docs API and semantic search | Yes | Unchanged |
| Bundled Laravel skills and guidelines | Yes | Re-rendered through this package's pipeline |
| Tag filtering | — | `withTags()`. Ship `inertia-vue-development` only on Inertia projects |
| Remote skill sources | — | `withRemoteSkills()`. Pull GitHub-published `.skill` bundles |
| Vendor allowlist | Automatic, via `composer.json` | Explicit `withAllowedVendors()`, for collision control |
| Origin tracing | — | `boost where` — host, vendor, remote, shadow |
| Injection-set tracing | — | `project-boost:where`, for the `laravel/boost` set this package injects |
| User-scope sync | — | `boost sync --scope=user`, for globally-installed CLI tools |
| Health check and path-repository audit | — | `boost doctor --check-versions` |

`project-boost:install` calls `boost:install --mcp`, so `laravel/boost` writes
its MCP client config exactly as it always does. `project-boost:sync` then takes
over for the nine-agent fan-out.

## Who owns which file

| Concern | Owner |
|---|---|
| MCP server, and the MCP config writes `boost:install` performs | `laravel/boost` |
| MCP config files (`.mcp.json`, `.amp/settings.json`, agent-specific) | `laravel/boost` |
| Laravel docs API and semantic search | `laravel/boost` |
| `CLAUDE.md`, `AGENTS.md`, `GEMINI.md` content | This package, through the engine |
| `.{agent}/skills/<name>/SKILL.md` files | This package, through the engine |
| Skill discovery and Blade rendering | This package — `LaravelBoostAssetReader` and `BladeRenderer` |
| Versioned-variant resolution, such as `pest/3` versus `pest/4` | This package, matched to the host's installed major |
| Tag filtering and collision resolution | The engine |
| Remote skill fetching | The engine |

The full sequence, the data-loss seam, and the `boost.json` hand-off are in
[Coexistence with `laravel/boost`](/guide/laravel-coexistence).

## Where the skills come from

Four sources stack:

1. **Your own `.ai/skills/`**, hand-authored. The same convention `laravel/boost`
   uses.
2. **A Composer-installed catalog** — any package shipping
   `resources/boost/skills/`. [`boost-skills`](/packages/boost-skills/) is one
   published example.
3. **Remote sources** through `withRemoteSkills()`.
4. **`laravel/boost`'s bundled Laravel skills**, plus the Laravel-aware
   third-party packages it knows about, picked up through the injection seam this
   package adds.

`withAllowedVendors()` gates source 2 only. `withTags()` filters sources 2, 3, and
4. Host skills bypass both. See [Skill sources](/guide/skill-sources).

## Architecture

`LaravelBoostAssetReader` and `LaravelBoostGuidelineReader` walk
`vendor/laravel/boost/.ai/`, render any `.blade.php` file through the package's
`BladeRenderer`, which uses `laravel/boost`'s own `RendersBladeGuidelines` trait
so `$assist` binds correctly, and hand the resulting skills and guidelines to the
engine. From there it is the normal pipeline: tag filter, collision resolution,
per-agent fan-out.

Guidelines are install-gated. Only the core guidelines ship, plus guidelines for
packages the host actually installed, mirroring `laravel/boost`'s own detection.
A Livewire, Filament, and PHPUnit application does not receive Inertia, Pest, or
Sail guidance. Version-major fragments are scoped on two axes: package
directories by exact installed major (a Laravel 12 application gets `laravel/12`,
never `laravel/11`), and `php/8.x` cumulatively downward to your declared
`require.php` floor.

A `BoostWrapper` class implements the engine's `BoostWrapperContract` and declares
the per-agent skill-emit paths this package injects, so a bare
`vendor/bin/boost sync` preserves those files instead of flagging them stale.
