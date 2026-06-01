<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\InstallCommand;
use SanderMuller\BoostCore\Config\BoostConfigPath;

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

it('scaffoldTarget: --config-dir picks .config/boost.php for a fresh project (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-tgt-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    try {
        $resolved = BoostConfigPath::resolve($dir);
        expect(InstallCommand::scaffoldTarget($resolved, true, $dir))->toBe($dir . '/.config/boost.php')
            ->and(InstallCommand::scaffoldTarget($resolved, false, $dir))->toBe($dir . '/boost.php');
    } finally {
        @rmdir($dir);
    }
});

it('scaffoldTarget: an existing config wins — --config-dir never creates a second (#89)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-install-existing-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');
    try {
        $resolved = BoostConfigPath::resolve($dir);
        // Even with --config-dir false, the existing .config/ config is edited in place.
        expect(InstallCommand::scaffoldTarget($resolved, false, $dir))->toBe($dir . '/.config/boost.php');
    } finally {
        @unlink($dir . '/.config/boost.php');
        @rmdir($dir . '/.config');
        @rmdir($dir);
    }
});
