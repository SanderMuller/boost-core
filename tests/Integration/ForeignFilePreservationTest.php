<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * The stale-managed sweep is clean-slate: everything under a boost-managed
 * gitignore pattern that this sync didn't rewrite used to be deleted. That
 * reaped files boost NEVER wrote — the concrete case being another tool
 * (laravel/boost) installing its own skill directories into `.claude/skills/`,
 * which `herd link` re-triggers via `php artisan boost:update`, producing a
 * delete/reinstall flip-flop with no operator signal.
 *
 * The invariant these tests pin: boost deletes only what its manifest owns.
 */
function foreignProjectRoot(): string
{
    $root = sys_get_temp_dir() . '/boost-foreign-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/skills', 0o755, recursive: true);
    mkdir($root . '/.ai/guidelines', 0o755, recursive: true);
    file_put_contents(
        $root . '/boost.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );
    file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\ndescription: A foo skill.\n---\n# Foo body\n");

    return $root;
}

function rmTreeForeign(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }

        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && ! is_link($full)) {
            rmTreeForeign($full);
        } else {
            unlink($full);
        }
    }

    rmdir($path);
}

/**
 * @param  list<WrittenFile>  $writes
 * @return list<string>
 */
function deletedPaths(array $writes): array
{
    $deleted = [];
    foreach ($writes as $write) {
        if ($write->action === WriteAction::DELETED || $write->action === WriteAction::WOULD_DELETE) {
            $deleted[] = $write->relativePath;
        }
    }

    return $deleted;
}

it('preserves a foreign skill directory another tool installed under a boost-managed dir', function (): void {
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        // laravel/boost `boost:install` / `boost:update` (which `herd link` runs
        // automatically) writes its bundled skills as real directories here.
        mkdir($root . '/.claude/skills/laravel-best-practices', 0o755, recursive: true);
        file_put_contents(
            $root . '/.claude/skills/laravel-best-practices/SKILL.md',
            "---\nname: laravel-best-practices\n---\nForeign body.\n",
        );

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/laravel-best-practices/SKILL.md'))->toBeTrue()
            ->and(deletedPaths($result->writes))->not->toContain('.claude/skills/laravel-best-practices/SKILL.md');

        $messages = implode("\n", array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            $result->diagnostics,
        ));

        expect($messages)->toContain('.claude/skills/laravel-best-practices/SKILL.md')
            ->toContain('boost doctor');
    } finally {
        rmTreeForeign($root);
    }
});

it('does not predict deleting a foreign file under --check', function (): void {
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        mkdir($root . '/.claude/skills/laravel-best-practices', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/laravel-best-practices/SKILL.md', "---\nname: laravel-best-practices\n---\nForeign body.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root, checkOnly: true);

        expect(deletedPaths($result->writes))->not->toContain('.claude/skills/laravel-best-practices/SKILL.md');
    } finally {
        rmTreeForeign($root);
    }
});

it('still reaps a stale file boost itself emitted on an earlier sync', function (): void {
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);
        expect(file_exists($root . '/.claude/skills/foo/SKILL.md'))->toBeTrue();

        // The host source is gone: boost owns the emitted file, so it must reap it.
        unlink($root . '/.ai/skills/foo.md');

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/foo/SKILL.md'))->toBeFalse()
            ->and(deletedPaths($result->writes))->toContain('.claude/skills/foo/SKILL.md');
    } finally {
        rmTreeForeign($root);
    }
});

it('preserves a foreign sibling file inside a skill directory boost owns', function (): void {
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        // Name collision: another tool wrote extra files into a directory whose
        // SKILL.md boost owns. Boost rewrites its own file and leaves the rest.
        file_put_contents($root . '/.claude/skills/foo/rules.md', "Foreign rules.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/foo/rules.md'))->toBeTrue()
            ->and(deletedPaths($result->writes))->not->toContain('.claude/skills/foo/rules.md');
    } finally {
        rmTreeForeign($root);
    }
});

it('preserves a foreign file under the managed commands directory too', function (): void {
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        // `.claude/commands/` is managed under the same clean-slate model as the
        // skills dir, and other tools install slash commands there.
        mkdir($root . '/.claude/commands', 0o755, recursive: true);
        file_put_contents($root . '/.claude/commands/foreign-command.md', "Foreign command.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        $messages = implode("\n", array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            $result->diagnostics,
        ));

        // The INFO proves the manifest gate is what spared it (rather than the
        // directory never being enumerated in the first place).
        expect(file_exists($root . '/.claude/commands/foreign-command.md'))->toBeTrue()
            ->and(deletedPaths($result->writes))->not->toContain('.claude/commands/foreign-command.md')
            ->and($messages)->toContain('.claude/commands/foreign-command.md');
    } finally {
        rmTreeForeign($root);
    }
});

it('keeps preserving a foreign file across repeated syncs — never adopts it into the manifest', function (): void {
    // Preservation is worthless if the manifest then adopts the file: the NEXT sync
    // would find it in the prior manifest and delete it. The sweep's own enumeration
    // is a raw directory walk, so the manifest write has to filter it.
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        mkdir($root . '/.claude/skills/laravel-best-practices', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/laravel-best-practices/SKILL.md', "---\nname: laravel-best-practices\n---\nForeign body.\n");

        SyncEngine::default(new InstalledPackages([]))->sync($root);
        SyncEngine::default(new InstalledPackages([]))->sync($root);
        $third = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/laravel-best-practices/SKILL.md'))->toBeTrue()
            ->and(deletedPaths($third->writes))->not->toContain('.claude/skills/laravel-best-practices/SKILL.md')
            ->and((string) file_get_contents($root . '/.boost/manifest.json'))
            ->not->toContain('laravel-best-practices');
    } finally {
        rmTreeForeign($root);
    }
});

it('never adopts a foreign file that pre-dates the first sync', function (): void {
    // The other door into the same bug: on the first sync there are no prior managed
    // patterns, so the sweep sees nothing — but the manifest write enumerates the
    // directory afterwards and would claim whatever is sitting there.
    $root = foreignProjectRoot();

    try {
        mkdir($root . '/.claude/skills/pre-existing', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/pre-existing/SKILL.md', "---\nname: pre-existing\n---\nForeign body.\n");

        SyncEngine::default(new InstalledPackages([]))->sync($root);
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/pre-existing/SKILL.md'))->toBeTrue()
            ->and((string) file_get_contents($root . '/.boost/manifest.json'))->not->toContain('pre-existing');
    } finally {
        rmTreeForeign($root);
    }
});

it('still protects a foreign file after every boost-owned output is gone', function (): void {
    // An emptied-out manifest is not the same as never having had one: it says boost
    // owns nothing right now. Treating it like an absent manifest would re-arm
    // clean-slate deletion exactly when a project stops emitting.
    $root = foreignProjectRoot();

    try {
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        mkdir($root . '/.claude/skills/laravel-best-practices', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/laravel-best-practices/SKILL.md', "---\nname: laravel-best-practices\n---\nForeign body.\n");

        // Drop the only host skill: boost's own output goes, and the manifest empties.
        unlink($root . '/.ai/skills/foo.md');
        SyncEngine::default(new InstalledPackages([]))->sync($root);
        SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_exists($root . '/.claude/skills/laravel-best-practices/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeForeign($root);
    }
});
