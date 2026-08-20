# Configuration

Every method is listed in the
[configuration reference](/reference/configuration). This page covers what is
specific to `package-boost-laravel`.

## A worked config

```php
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::COPILOT, Agent::CODEX])
    ->withAllowedVendors([
        'sandermuller/boost-skills',
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withTags([Tag::Php, Tag::Laravel, Tag::Github, 'release-automation']);
```

Allowlist **both** `package-boost-laravel` and `package-boost-php`: the Laravel
package inherits the PHP package's content, but each vendor is allowlisted on its
own name.

## Tags

- `release-automation` — pulls the release-flow skills (`readme`,
  `release-notes`, `upgrading`) from `boost-skills`.
- `boost-extension` — pulls `skill-authoring` and `writing-file-emitter`. Add it
  if you author a custom `FileEmitter`.

The three Laravel skills are untagged, so they ship regardless.

## Inheritance and coexistence

Three relationships:

- **Inherits from `package-boost-php`.** A hard Composer dependency. Everything
  that package ships is available without re-declaring it. This package layers
  the Laravel skills, the `laravel-packages` guideline, and `McpJsonEmitter` on
  top.
- **Coexists with `laravel/boost` inside the package's test app.** The concerns
  are disjoint: this package is dev-time package authorship, and `laravel/boost`
  is the MCP server plus Laravel docs API your agent talks to once running.
  `McpJsonEmitter` is the seam that points the MCP client at
  `vendor/bin/testbench boost:mcp`.
- **Serves Laravel package projects.** The `laravel-packages` guideline activates
  only when `composer.json` declares `require.illuminate/*` or
  `require.laravel/framework`.

## Auto-sync on `composer install`

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"]
}
```

`BOOST_SKIP_AUTOSYNC=1` disables the callback. boost-core ships no Composer
plugin, so this hook is the opt-in. See
[Automating the sync](/guide/automating-sync).
