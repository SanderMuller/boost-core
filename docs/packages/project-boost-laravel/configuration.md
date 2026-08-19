# Configuration

Every method is listed in the
[configuration reference](/reference/configuration). This page covers what is
specific to `project-boost-laravel`.

::: tip Config location
The engine reads `.config/boost.php` (canonical) or a root `boost.php`. This
package's commands honour both. `laravel/boost`'s own `boost.json` is a separate
file that `laravel/boost` owns and resolves from the project root. It stays
there.
:::

## Minimal config

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::CODEX])
    ->withTags([Tag::Laravel, Tag::Php]);
```

## Common tag shapes

| Project | Tags |
|---|---|
| Laravel with Livewire | `Tag::Laravel, Tag::Php, 'livewire'` |
| Laravel with Inertia and React | `Tag::Laravel, Tag::Php, 'frontend', 'inertia'` |
| Laravel API only | `Tag::Laravel, Tag::Php` |
| Add Pest 4 and browser tests | add `'pest'` |

`vendor/bin/boost tags` lists every tag your installed packages declare. The
filtering rule is in [Tags and dependencies](/guide/tags-and-dependencies).

## Commands

| Command | Does |
|---|---|
| `project-boost:install` | Wraps `boost:install --mcp` and runs the sync. Auto-detects non-TTY for CI and Docker. The recommended entry point |
| `project-boost:install --no-sync` | MCP only. Skip the sync |
| `project-boost:sync` | Discover, render, tag-filter, and fan out to ten agents. Run after `composer install` or after editing `boost.php` |
| `project-boost:sync --keep-boost-json` | Leave `laravel/boost`'s `boost.json` in place. By default a successful sync archives it |
| `project-boost:sync --dry-run` | Preview the full pipeline in check mode |
| `project-boost:where` | List the `laravel/boost`-bundled skills and guidelines this package injects, with per-skill ship, tag-filter, and shadow status |
| `project-boost:reconcile` | Capture `laravel/boost`-seeded guidance into `.ai/guidelines/` before a sync would overwrite it, back each file up, then sync |

## Auto-sync on `composer install`

```jsonc
{
  "scripts": {
    "post-install-cmd": ["@php artisan project-boost:sync"],
    "post-update-cmd": ["@php artisan project-boost:sync"]
  }
}
```

### Why not `BoostAutoSync::run` here?

The engine's `BoostAutoSync::run` helper invokes the bare `vendor/bin/boost sync`.
In a Laravel application using this package that is the wrong hook: the bare CLI
bypasses the injection pipeline, so the `laravel/boost` bundled skill set never
reaches your agent directories. The sync still reports success, just against a
smaller skill set, which is why the mistake usually goes unnoticed.

Use `@php artisan project-boost:sync`. It routes through the wrapper, which walks
`vendor/laravel/boost/.ai/`, pre-renders Blade with proper container context, and
injects the bundled skills into the sync call.

A stray bare-CLI sync no longer *deletes* the wrapper's emitted skill files, because the
`BoostWrapper` contract declares them, so the cleanup pass leaves them alone. It
still will not *re-emit* the `laravel/boost` set.

For a non-Laravel project consuming the engine directly, `BoostAutoSync::run` is
the correct hook. This guidance is Laravel-specific.

## Defensive flag: `suppress_upstream_writers`

```dotenv
PROJECT_BOOST_SUPPRESS_UPSTREAM=true
```

Any truthy value works. It enables a `CommandStarting` listener that intercepts an
ad-hoc `php artisan boost:install` and injects `--mcp` before the command runs.

Off by default, because `project-boost:install` already does the right thing in
both TTY and non-TTY modes. Treat the flag as belt and braces for a team worried
about muscle memory.

It does not suppress `laravel/boost`'s integrations writers (cloud, Sail,
Nightwatch). `--mcp` short-circuits feature selection only. The integrations
multiselect still runs in TTY mode, and selecting one triggers its writer.

## Things to avoid

- **A bare `vendor/bin/boost sync` on this project.** It bypasses the injection,
  assembles a smaller guidance set, and wholesale-overwrites your files with it.
- **`php artisan boost:install` without `--mcp`.** The interactive default
  re-engages `laravel/boost`'s writers, which then race this package.
- **`php artisan boost:update`.** It rewrites the guidance files inside its own
  marker and reinstalls its skill directories, so the next sync reports a
  takeover. Retiring `boost.json`, which a successful sync does for you, makes
  it a no-op.

## Remote skills

Declared with
`withRemoteSkills([RemoteSkillSource::githubBundle(...), RemoteSkillSource::githubPath(...)])`.
The mechanism, the cache, `BOOST_GITHUB_TOKEN`, and `BOOST_REMOTE_STRICT` behave
exactly as in the engine. See [Remote skills](/guide/remote-skills).
