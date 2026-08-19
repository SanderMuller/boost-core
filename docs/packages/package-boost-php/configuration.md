# Configuration

`boost.php` lives in the repository root, or in `.config/boost.php`. Every
method is listed in the [configuration reference](/reference/configuration); this
page covers what is specific to `package-boost-php`.

## The minimum

One agent, and this package in the allowlist:

```php
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors(['sandermuller/package-boost-php']);
```

## A worked config

This is the package's own dogfood config:

```php
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-core',
        'sandermuller/boost-skills',
        'sandermuller/package-boost-php',
        'stolt/lean-package-validator',
    ])
    ->withTags([
        Tag::Php,
        Tag::Github,
        'release-automation',
        'boost-extension',
    ]);
```

## Opt-in tags

This package recognises two tags. Both are off until you declare them:

- `release-automation` — pulls this package's `release-automation` guideline, and
  (when `sandermuller/boost-skills` is allowlisted) the `readme`,
  `release-notes`, and `upgrading` skills that moved there.
- `boost-extension` — pulls `skill-authoring` and `writing-file-emitter`, for
  consumers extending the boost ecosystem itself.

The tag *mechanism* is documented in
[Tags and dependencies](/guide/tags-and-dependencies). The tag *vocabulary* is
per catalog: `boost-skills` publishes one worked example, and another catalog may
organise differently.

## Auto-sync on `composer install`

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\PackageBoostPhp\\Scripts\\AutoSync::run"],
    "post-update-cmd": ["SanderMuller\\PackageBoostPhp\\Scripts\\AutoSync::run"]
}
```

The callback lives under this package's own namespace, so you reference only
`package-boost-php`. `AutoSync::run` delegates to the engine and inherits every
guard: it is silent on a no-op install, skips on `--no-dev`, and honours
`BOOST_SKIP_AUTOSYNC=1`.

For a script you invoke yourself — `composer sync-ai`, say — where silence reads
as nothing happening, use
`SanderMuller\PackageBoostPhp\Scripts\AutoSync::runWithSummary` instead. It
always prints the one-line summary. See
[Automating the sync](/guide/automating-sync).

## Coexistence

`package-boost-laravel` requires this package and layers Laravel-specific skills
(Testbench, cross-version Laravel, CI matrix) and the `.mcp.json` emitter on top.

Both coexist with `laravel/boost` in a Laravel package project, because the
concerns are disjoint: this package is dev-time package authorship, and
`laravel/boost` is install-time MCP for the downstream Laravel application.
