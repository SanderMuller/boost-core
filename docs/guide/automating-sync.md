# Automating the sync

boost-core ships no Composer plugin, so a `composer install` re-sync is opt-in.
Pick the entry point that fits:

| Entry point                        | Use for                                                             |
|------------------------------------|---------------------------------------------------------------------|
| `BoostAutoSync::run`               | `post-install-cmd` / `post-update-cmd` hooks — silent on a no-op    |
| `BoostAutoSync::runWithSummary`    | User-invoked scripts (`composer sync-ai`) — prints a summary always |
| `BoostAutoSync::syncUserScopeOnce` | A globally-installed CLI tool self-syncing its own bundled skills   |

All three honor `BOOST_SKIP_AUTOSYNC=1`.

## Composer hook (consumer project)

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "sync-ai":          ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::runWithSummary"]
}
```

`run` checks `Event::isDevMode()`, resolves the bin-dir, runs `vendor/bin/boost sync`,
surfaces non-zero exits through Composer's IO, and is **silent on a true no-op**
(`wrote=0, deleted=0`). Output appears only when something changed or errored.
`runWithSummary` prints the one-line success summary on *every* sync, including
the no-ops `run` keeps quiet (useful when debugging "did the hook fire?"). Both
work on Windows + Unix.

> [!IMPORTANT]
> **On Laravel + [`project-boost-laravel`](https://github.com/sandermuller/project-boost-laravel)**,
> use `@php artisan project-boost:sync` instead of `BoostAutoSync::run`. The
> artisan path runs through the Laravel container, which bootstraps `BladeRenderer`
> and delivers laravel/boost's bundled skills to every agent. The bare-CLI path
> bypasses both. See `project-boost-laravel`'s install guide for the canonical
> `scripts` shape.

## CLI tool you publish (self-sync from bin script)

A tool installed with `composer global require` keeps its own bundled skills
current by self-syncing from its bin script:

```php
#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\SanderMuller\BoostCore\Scripts\BoostAutoSync::syncUserScopeOnce(
    packageRoot: dirname(__DIR__),
    packageName: 'your-vendor/your-tool',
);

// ... the tool's own dispatch ...
```

`syncUserScopeOnce()` runs a user-scope sync the first time it sees a given
version, then writes a per-version sentinel so later runs are free.
`syncUserScope()` is the ungated form. Both never throw, so the tool keeps running
even if its sync fails.

## User-scope sync

After `composer global require`-ing skill-bearing packages, run
`vendor/bin/boost sync --scope=user --all` once to user-scope-sync every globally
installed package that ships `resources/boost/skills/`, into
`~/.{agent}/skills/<vendor>__<package>/<skill>/SKILL.md`. User scope publishes a
package's skills **wholesale**: there's no `boost.php`, so tag filters and the
vendor allowlist (both project-scope controls) don't apply. Removed packages are
reaped on the next `--all` run; see [file-ownership.md](file-ownership.md).
