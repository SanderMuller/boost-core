# Installation

Install the family package that matches your project. If you have not picked one
yet, start at [Which package fits](/guide/which-package).

```bash
composer require --dev sandermuller/project-boost-php        # PHP application
composer require --dev sandermuller/project-boost-laravel    # Laravel application
composer require --dev sandermuller/package-boost-php        # Composer package
composer require --dev sandermuller/package-boost-laravel    # Laravel package
composer require --dev sandermuller/boost-core               # the bare engine
```

Every family package requires PHP 8.3 or later.

## First run

```bash
vendor/bin/boost install   # scaffold boost.php + pick agents, vendor allowlist, tags
vendor/bin/boost sync      # fan out to the selected agents
vendor/bin/boost sync --check   # dry run — report drift, write nothing
```

On Laravel with `project-boost-laravel`, run `php artisan project-boost:sync`
instead of the bare CLI. The artisan path runs through the Laravel container,
which bootstraps the Blade renderer and delivers `laravel/boost`'s bundled skills
to every agent. See [Coexistence with `laravel/boost`](/guide/laravel-coexistence).

## The config file

`boost install` writes `boost.php` to the repository root. `.config/boost.php` is
also read, if you prefer to keep the root clean. A minimal config:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::CURSOR,
    ]);
```

Every method is listed in the [configuration reference](/reference/configuration).

## Adopting boost in a repository that already has agent files

Safe by default. boost tracks what it owns in a manifest, and a file whose
checksum does not match the manifest is never blanked or reaped. An existing
hand-written `CLAUDE.md` survives the first sync.

Move that hand-written content into `.ai/guidelines/` when you are ready. From
then on the guidance files are generated, and you edit the source instead of the
target. [File ownership](/guide/file-ownership) has the full lifecycle.

## Verify

```bash
vendor/bin/boost where     # what would ship, and where each item comes from
vendor/bin/boost doctor    # offline health check — config, cache, emitters, tokens
```

`boost doctor` is advisory and exits 0 unless the config fails to load. Gate CI
on `boost sync --check` or `boost validate --strict` instead.
