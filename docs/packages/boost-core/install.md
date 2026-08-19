# Install and first run

```bash
composer require --dev sandermuller/boost-core
```

It needs PHP 8.3 or later and depends on no framework.

The engine runs no install-time code of its own: it ships no Composer plugin. You
run the sync yourself, in CI or through the
[auto-sync hook](/guide/automating-sync).

## First run

```bash
vendor/bin/boost install        # scaffold boost.php + pick agents, vendors, tags
vendor/bin/boost sync           # fan out to the selected agents
vendor/bin/boost sync --check   # dry run — report drift, write nothing
```

A minimal `boost.php`:

```php
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::CURSOR,
    ]);
```

`.config/boost.php` is read as well, if you prefer to keep the root clean.
Declaring both is an error.

## Author some content

```bash
vendor/bin/boost new skill my-skill --description="What it does."
vendor/bin/boost new guideline house-style
```

Then re-run `vendor/bin/boost sync`.

## Verify

```bash
vendor/bin/boost where     # what ships, and where each item came from
vendor/bin/boost doctor    # offline health check
vendor/bin/boost paths     # the path globs boost manages
```

## Testing the engine itself

```bash
composer test            # full Pest suite — unit and integration, with real composer-install subprocesses
composer test-coverage   # with a coverage report
```
