# Contributing

Thanks for considering a contribution. boost-core is a small framework-free PHP package, so the development loop is intentionally light.

## Development setup

```bash
git clone https://github.com/sandermuller/boost-core
cd boost-core
composer install
```

`composer install` triggers the plugin's own auto-sync, which regenerates the agent fan-out files (`.claude/skills/<name>/SKILL.md`, etc.) from `.ai/`. The fan-out is gitignored; only `.ai/` sources are tracked.

## Running checks

The repository ships `composer` scripts for each quality gate:

```bash
composer test            # Pest suite (unit + integration)
composer test-coverage   # same, with coverage report
composer pint            # Laravel Pint code style
composer phpstan         # PHPStan at max level + bleeding edge + disallowed-calls
composer rector          # Rector refactor pass
```

Before opening a PR, run `composer test` + `composer phpstan` locally. CI runs the matrix on push.

## Code style

- Pint config is `.gitignored`-default (no `pint.json`); follow what the linter emits.
- PHPStan max level. Don't pad the baseline — fix the real issue, or leave a one-line `@phpstan-ignore` with a rationale.
- Tests use Pest; existing tests are the convention. Integration tests that spawn real `composer install` subprocesses live in `tests/Integration/`.

## Pull requests

- One feature/fix per PR. Prefer small, focused commits.
- Reference the related issue if any.
- Mention behaviour changes in CHANGELOG.md under `## [Unreleased]` (CI auto-prepends the release body on tag publish, so don't manually convert `Unreleased` to a version header).

## Releases

Maintainers only. See `RELEASING.md` for the pre-release gauntlet (Rector → Pint → tests → PHPStan → docs audit → CI gate → tag).

## Security

Security issues: see [`SECURITY.md`](SECURITY.md). Don't file them as public issues.
