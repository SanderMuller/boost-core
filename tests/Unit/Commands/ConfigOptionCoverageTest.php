<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\PathsCommand;
use SanderMuller\BoostCore\Commands\SlotsCommand;
use SanderMuller\BoostCore\Commands\TagsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.0 #115: `slots`, `tags`, and `paths` must honor `--config` like the other
 * commands (README promises "every command accepts --config"). Without it, a
 * `.config/`-layout or non-default config location can't be pointed at.
 */
function configCoverageProject(): string
{
    $dir = sys_get_temp_dir() . '/boost-cfgcov-' . bin2hex(random_bytes(8));
    mkdir($dir . '/cfg', 0o755, recursive: true);
    // NO boost.php at the project root — only at a custom path reachable via --config.
    file_put_contents(
        $dir . '/cfg/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );

    return $dir;
}

function rmTreeCfgCov(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $f */
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

it('honors --config on slots/tags/paths (and fails without it when root has no boost.php)', function (string $commandClass): void {
    $dir = configCoverageProject();

    try {
        /** @var Command $command */
        $command = new $commandClass();

        // Without --config: no boost.php at the project root → config-not-found failure.
        $without = new CommandTester($command);
        $without->execute(['--working-dir' => $dir]);
        expect($without->getStatusCode())->toBe(Command::FAILURE, "{$commandClass} should fail when no config is discoverable");

        // With --config pointing at the relocated boost.php: loads and proceeds.
        $with = new CommandTester($command);
        $with->execute(['--working-dir' => $dir, '--config' => 'cfg/boost.php']);
        expect($with->getStatusCode())->toBe(Command::SUCCESS, "{$commandClass} should load the --config target");
    } finally {
        rmTreeCfgCov($dir);
    }
})->with([
    'slots' => [SlotsCommand::class],
    'tags' => [TagsCommand::class],
    'paths' => [PathsCommand::class],
]);
