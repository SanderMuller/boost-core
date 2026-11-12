<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\UserScopeDriftReporter;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A fake global Composer vendor dir holding one skill-shipping tool, and a
 * fake home the tool was user-scope synced into. Never the real home.
 *
 * @return array{root: string, package: string, home: string}
 */
function driftFixture(string $installedCoreVersion = '9.9.9'): array
{
    $root = sys_get_temp_dir() . '/boost-drift-' . bin2hex(random_bytes(8));
    $package = $root . '/vendor/acme/tool';
    $home = $root . '/home';
    mkdir($package . '/resources/boost/skills/sample', 0o755, recursive: true);
    mkdir($root . '/vendor/composer', 0o755, recursive: true);
    mkdir($home, 0o755, recursive: true);
    file_put_contents($package . '/composer.json', json_encode(['name' => 'acme/tool'], JSON_THROW_ON_ERROR));
    file_put_contents($package . '/resources/boost/skills/sample/SKILL.md', "---\nname: sample\ndescription: Sample.\n---\nFirst body.\n");
    file_put_contents($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'sandermuller/boost-core', 'version' => $installedCoreVersion]],
    ], JSON_THROW_ON_ERROR));

    SyncEngine::default()->syncUser($package, homeRoot: $home);

    return ['root' => $root, 'package' => $package, 'home' => $home];
}

function runDriftReport(string $home, string $runningCoreVersion = '9.9.9', int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
{
    $output = new BufferedOutput($verbosity);
    (new UserScopeDriftReporter($runningCoreVersion))->report(new SymfonyStyle(new ArrayInput([]), $output), $home);

    return $output->fetch();
}

/**
 * @return array<string, string>  path => sha256 of every file under `$dir`
 */
function driftTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $snapshot[$file->getPathname()] = (string) hash_file('sha256', $file->getPathname());
    }

    ksort($snapshot);

    return $snapshot;
}

it('stays silent when the user-scope copies match their packages', function (): void {
    $fixture = driftFixture();

    try {
        expect(runDriftReport($fixture['home']))
            ->toBeEmpty();
    } finally {
        cleanupTestDir($fixture['root']);
    }
});

it('names the drifted package and prints the exact command, without writing anything', function (): void {
    $fixture = driftFixture();

    try {
        // The package was updated, but no user-scope sync followed.
        file_put_contents($fixture['package'] . '/resources/boost/skills/sample/SKILL.md', "---\nname: sample\ndescription: Sample.\n---\nSecond body.\n");
        $before = driftTreeSnapshot($fixture['home']);

        $display = runDriftReport($fixture['home']);

        expect($display)->toContain('acme/tool')
            ->and($display)->toContain('composer global exec -- boost sync --scope=user --all')
            ->and(driftTreeSnapshot($fixture['home']))->toBe($before)
            ->and((string) file_get_contents($fixture['home'] . '/.claude/skills/sample-user/SKILL.md'))->toContain('First body.');
    } finally {
        cleanupTestDir($fixture['root']);
    }
});

it('skips a package whose global boost-core is a different version, saying so only under -v', function (): void {
    $fixture = driftFixture('9.9.9');

    try {
        file_put_contents($fixture['package'] . '/resources/boost/skills/sample/SKILL.md', "---\nname: sample\ndescription: Sample.\n---\nSecond body.\n");

        // A different engine can render differently, so its verdict would be
        // a false drift that the suggested command cannot clear.
        expect(runDriftReport($fixture['home'], '1.0.0'))
            ->toBeEmpty()
            ->and(runDriftReport($fixture['home'], '1.0.0', OutputInterface::VERBOSITY_VERBOSE))->toContain('9.9.9');
    } finally {
        cleanupTestDir($fixture['root']);
    }
});

it('skips a manifest whose package is no longer installed', function (): void {
    $fixture = driftFixture();

    try {
        cleanupTestDir($fixture['package']);

        expect(runDriftReport($fixture['home']))
            ->toBeEmpty();
    } finally {
        cleanupTestDir($fixture['root']);
    }
});

it('says the copies could not be checked when the check fails, rather than calling them current', function (): void {
    $fixture = driftFixture();

    try {
        // The first sync already created `.boost/` for its manifest.
        file_put_contents($fixture['home'] . '/.boost/user-scope.php', "<?php\n\nreturn ['unknown-key' => true];\n");

        $display = runDriftReport($fixture['home']);

        expect($display)->toContain('acme/tool')
            ->and($display)->toContain('Could not check');
    } finally {
        cleanupTestDir($fixture['root']);
    }
});

it('does nothing when the home has no user-scope manifests', function (): void {
    $home = sys_get_temp_dir() . '/boost-drift-empty-' . bin2hex(random_bytes(8));
    mkdir($home, 0o755, recursive: true);

    try {
        expect(runDriftReport($home))
            ->toBeEmpty();
    } finally {
        cleanupTestDir($home);
    }
});
