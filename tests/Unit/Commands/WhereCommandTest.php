<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\WhereCommand;
use Symfony\Component\Console\Tester\CommandTester;

function whereTempProject(string $boostBody): string
{
    $dir = sys_get_temp_dir() . '/boost-where-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.ai/skills', 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nuse SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\nreturn {$boostBody};\n",
    );

    return $dir;
}

function whereCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $f */
    foreach ($iter as $f) {
        $path = $f->getPathname();
        $f->isDir() ? @rmdir($path) : @unlink($path);
    }

    @rmdir($dir);
}

function whereHostSkill(string $dir, string $name): void
{
    $skillDir = $dir . '/.ai/skills/' . $name;
    mkdir($skillDir, 0o755, recursive: true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: {$name}\ndescription: Test skill.\n---\n\nBody.\n");
}

/**
 * @return array{exit: int, display: string}
 */
function runWhere(string $dir): array
{
    $command = new WhereCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute(['--working-dir' => $dir]);

    return ['exit' => $exit, 'display' => $tester->getDisplay()];
}

it('boost where: host skills land under the `host` group', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');
        whereHostSkill($dir, 'review');

        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('host')
            ->and($result['display'])->toContain('.ai/skills/ (host)')
            ->and($result['display'])->toContain('• deploy')
            ->and($result['display'])->toContain('• review');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: prints a friendly empty-state when nothing resolves', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('No skills resolved');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: never emits the old ambiguous `vendor or remote` label', function (): void {
    // Regression for the rc2 → 0.7.x UX work that split the combined
    // label into the distinct `host` / `vendor` / `remote` / `vendor+remote`
    // tags. A host-only project (no vendor/remote skills) must not show
    // the legacy combined string anywhere in its output.
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');
        $result = runWhere($dir);
        expect($result['display'])->not->toContain('vendor or remote');
    } finally {
        whereCleanup($dir);
    }
});
