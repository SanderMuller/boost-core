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
composer format          # Laravel Pint code style
composer phpstan         # PHPStan at max level + bleeding edge + disallowed-calls
composer rector          # Rector refactor pass
composer qa              # rector + format + phpstan + test, in that order
```

Before opening a PR, run `composer qa` locally. CI runs the matrix on push.

## Code style

- Pint config is `.gitignored`-default (no `pint.json`); follow what the linter emits.
- PHPStan max level. Don't pad the baseline — fix the real issue, or leave a one-line `@phpstan-ignore` with a rationale.
- Tests use Pest; existing tests are the convention. Integration tests that spawn real `composer install` subprocesses live in `tests/Integration/`.

## Pull requests

- One feature/fix per PR. Prefer small, focused commits.
- Reference the related issue if any.
- Mention behaviour changes in CHANGELOG.md under `## [Unreleased]` (CI auto-prepends the release body on tag publish, so don't manually convert `Unreleased` to a version header).

## Patterns

### Wording-revert-as-regression-test

When a wording revert is decided in a thread (e.g., a diagnostic message changes from "X" to "Y", or a next-steps copy is rewritten), ship the revert with a test that asserts the **new** wording AND fails on the **old** wording. The pattern catches a class of stale-string bug where the source-level decision reads correct but downstream-visible text (CLI output, diagnostics, release notes prose) didn't sync to the new copy.

Three documented uses so far: `DiagnosticCopyLockTest.php` (locked diagnostic copy), `ConvertConventionsCommandTest.php` (locked-out the reverted `git rm --cached` next-step instruction), the Copilot guideline-file strip diagnostic (locked the "strip don't delete" wording). The shape: in the test, `expect($output)->toContain($new)->and($output)->not->toContain($old)`. Cheap to write, deterministic against the bug class.

#### Meta-rule: when to codify a pattern

This pattern was codified after **three observed uses** across three release cycles, not after the first. The general meta-rule: **wait for 2-3 occurrences across distinct contexts before promoting a pattern to documented convention**. The wait prevents two failure modes:

- **Premature codification**: a one-off becomes a load-bearing rule the next contributor follows ritualistically, even when the original context no longer applies.
- **Under-codification**: a pattern reused N times across cycles never gets written down, so each new contributor reinvents it (often slightly differently, eroding the consistency the pattern would have enforced).

Apply when you notice a pattern emerging in PR comments or commit messages. Count the distinct uses across cycles; codify here once the count reaches three.

## Releases

Maintainers only. See `RELEASING.md` for the pre-release gauntlet (Rector → Pint → tests → PHPStan → docs audit → CI gate → tag).

## Security

Security issues: see [`SECURITY.md`](SECURITY.md). Don't file them as public issues.
