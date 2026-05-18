<?php

declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\NewCommand;
use Symfony\Component\Console\Tester\CommandTester;

function newTempDir(): string
{
    $dir = sys_get_temp_dir() . '/boost-new-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nreturn BoostConfig::configure();\n",
    );

    return $dir;
}

function rmTreeNew(string $path): void
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
            rmTreeNew($full);
        } else {
            unlink($full);
        }
    }

    rmdir($path);
}

/**
 * @param  array<string, string|bool>  $args
 * @return array{exit: int, tester: CommandTester}
 */
function runNew(array $args): array
{
    $command = new NewCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute($args);

    return ['exit' => $exit, 'tester' => $tester];
}

it('creates a skill file with frontmatter', function (): void {
    $dir = newTempDir();
    try {
        $result = runNew([
            'type' => 'skill',
            'name' => 'foo-bar',
            '--working-dir' => $dir,
            '--description' => 'A foo-bar skill.',
        ]);

        expect($result['exit'])->toBe(0)
            ->and(file_exists($dir . '/.ai/skills/foo-bar.md'))
            ->toBeTrue();

        $contents = (string) file_get_contents($dir . '/.ai/skills/foo-bar.md');
        expect($contents)->toContain('name: foo-bar')
            ->toContain('description: A foo-bar skill.')
            ->toContain('# foo-bar');
    } finally {
        rmTreeNew($dir);
    }
});

it('creates a guideline file in the guidelines dir', function (): void {
    $dir = newTempDir();
    try {
        $result = runNew([
            'type' => 'guideline',
            'name' => 'conventions',
            '--working-dir' => $dir,
        ]);

        expect($result['exit'])->toBe(0)
            ->and(file_exists($dir . '/.ai/guidelines/conventions.md'))
            ->toBeTrue();
    } finally {
        rmTreeNew($dir);
    }
});

it('refuses to overwrite without --force', function (): void {
    $dir = newTempDir();
    try {
        runNew(['type' => 'skill', 'name' => 'foo', '--working-dir' => $dir]);
        $second = runNew(['type' => 'skill', 'name' => 'foo', '--working-dir' => $dir]);

        expect($second['exit'])->toBe(1);
    } finally {
        rmTreeNew($dir);
    }
});

it('overwrites with --force', function (): void {
    $dir = newTempDir();
    try {
        runNew(['type' => 'skill', 'name' => 'foo', '--working-dir' => $dir]);
        $second = runNew([
            'type' => 'skill',
            'name' => 'foo',
            '--working-dir' => $dir,
            '--force' => true,
            '--description' => 'Updated.',
        ]);

        expect($second['exit'])->toBe(0)
            ->and((string) file_get_contents($dir . '/.ai/skills/foo.md'))
            ->toContain('Updated.');
    } finally {
        rmTreeNew($dir);
    }
});

it('rejects unknown types', function (): void {
    $dir = newTempDir();
    try {
        $result = runNew(['type' => 'rule', 'name' => 'foo', '--working-dir' => $dir]);

        expect($result['exit'])->toBe(1);
    } finally {
        rmTreeNew($dir);
    }
});
