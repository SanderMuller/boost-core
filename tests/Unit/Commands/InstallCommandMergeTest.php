<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\InstallCommand;

it('merges picker output with declared-but-not-discovered tags, picker order first, dedup', function (): void {
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'laravel'],
        preserved: ['org-internal', 'phpstan-strict'],
    );

    expect($merged)->toBe(['php', 'laravel', 'org-internal', 'phpstan-strict']);
});

it('mergePickedWithPreserved: deduplicates overlap between the two sets', function (): void {
    // Defensive: if a tag somehow appears in BOTH the discovered-and-picked
    // set AND the preserved set (would mean array_diff failed in caller),
    // the merge should still produce a unique list.
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'shared'],
        preserved: ['shared', 'org-internal'],
    );

    expect($merged)->toBe(['php', 'shared', 'org-internal']);
});

it('mergePickedWithPreserved: empty picker keeps preserved set', function (): void {
    // Regression guard: the operator un-checked every visible tag but
    // had declared org-internal — that tag must survive.
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: [],
        preserved: ['org-internal'],
    );

    expect($merged)->toBe(['org-internal']);
});

it('mergePickedWithPreserved: empty preserved returns picker output unchanged', function (): void {
    $merged = InstallCommand::mergePickedWithPreserved(
        picked: ['php', 'jira'],
        preserved: [],
    );

    expect($merged)->toBe(['php', 'jira']);
});
