# Configuration

Every method is listed in the
[configuration reference](/reference/configuration). This page covers what is
specific to `project-boost-php`.

## Minimal config

```php
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::CODEX])
    ->withAllowedVendors(['sandermuller/project-boost-php']);
```

`withAllowedVendors()` is explicit on purpose: an installed dependency's skills
sync only when its package name is listed.

## Where the skills come from

This package is one source. Sources stack:

1. **Hand-authored** skills in your project's `.ai/skills/`. A host skill shadows
   a vendor skill of the same name.
2. **Composer-installed catalogs** that ship `resources/boost/skills/`. This
   package is one. [`boost-skills`](/packages/boost-skills/) is another.
3. **Remote sources** through `withRemoteSkills()` — GitHub `.skill` bundles or
   single-skill repositories. No Composer needed.

`withAllowedVendors()` gates source 2 only. `withTags()` filters sources 2 and 3.
Host skills bypass both: your project authored them, so the engine treats them as
canonical. See [Skill sources](/guide/skill-sources).

In a Laravel application, `laravel/boost`'s bundled skills are a fourth source.
[`project-boost-laravel`](/packages/project-boost-laravel/) is what surfaces them.

## Excluding a shipped skill

```php
->withExcludedSkills(['sandermuller/project-boost-php:legacy-coexistence'])
```

## Coexistence

- **Laravel application?** Install
  [`project-boost-laravel`](/packages/project-boost-laravel/) instead.
- **A Composer package rather than an application?** Install
  [`package-boost-php`](/packages/package-boost-php/). The skills here assume an
  application, and the `foundation` framing diverges from package-author rules.
- **Mixed stack?** Allowlist several vendors. A host override in `.ai/skills/`
  shadows a vendor copy of the same name, and a collision between two vendors
  surfaces as a sync error with a one-line resolution hint.

## Auto-sync on `composer install`

```json
"scripts": {
    "post-install-cmd": ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"],
    "post-update-cmd":  ["SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"]
}
```

`BOOST_SKIP_AUTOSYNC=1` disables the callback. See
[Automating the sync](/guide/automating-sync).
