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

function whereHostGuideline(string $dir, string $name, string $body = "# Conventions\n\nFollow the rules.\n"): void
{
    if (! is_dir($dir . '/.ai/guidelines')) {
        mkdir($dir . '/.ai/guidelines', 0o755, recursive: true);
    }

    file_put_contents($dir . '/.ai/guidelines/' . $name . '.md', $body);
}

function whereHostCommand(string $dir, string $name, string $body = "Ship it.\n"): void
{
    if (! is_dir($dir . '/.ai/commands')) {
        mkdir($dir . '/.ai/commands', 0o755, recursive: true);
    }

    file_put_contents($dir . '/.ai/commands/' . $name . '.md', $body);
}

it('boost where: host skills land under the SKILLS section / `host` group', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');
        whereHostSkill($dir, 'review');

        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('SKILLS')
            ->and($result['display'])->toContain('host')
            ->and($result['display'])->toContain('.ai/skills/ (host)')
            ->and($result['display'])->toContain('• deploy')
            ->and($result['display'])->toContain('• review');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: host guidelines land under the GUIDELINES section', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostGuideline($dir, 'core');
        whereHostGuideline($dir, 'naming');

        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('GUIDELINES')
            ->and($result['display'])->toContain('.ai/guidelines/ (host)')
            ->and($result['display'])->toContain('• core')
            ->and($result['display'])->toContain('• naming');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: host commands land under the COMMANDS section', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostCommand($dir, 'deploy');

        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('COMMANDS')
            ->and($result['display'])->toContain('.ai/commands/ (host)')
            ->and($result['display'])->toContain('• deploy');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: renders all three sections together when all three sources are populated', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');
        whereHostGuideline($dir, 'core');
        whereHostCommand($dir, 'ship');

        $result = runWhere($dir);
        $display = $result['display'];
        $skillsPos = strpos($display, 'SKILLS');
        $guidelinesPos = strpos($display, 'GUIDELINES');
        $commandsPos = strpos($display, 'COMMANDS');
        expect($result['exit'])->toBe(0)
            ->and($skillsPos)->toBeInt()
            ->and($guidelinesPos)->toBeInt()
            ->and($commandsPos)->toBeInt()
            ->and($skillsPos)->toBeLessThan((int) $guidelinesPos)
            ->and($guidelinesPos)->toBeLessThan((int) $commandsPos);
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: omits a category section entirely when that category has nothing', function (): void {
    // Regression guard: a project with only skills should NOT render an
    // empty GUIDELINES / COMMANDS header. The category-renderer bails
    // early on an empty group set.
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');

        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('SKILLS')
            ->and($result['display'])->not->toContain('GUIDELINES')
            ->and($result['display'])->not->toContain('COMMANDS');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: prints a friendly empty-state when nothing resolves at all', function (): void {
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        $result = runWhere($dir);
        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Nothing resolved');
    } finally {
        whereCleanup($dir);
    }
});

it('boost where: never emits the old ambiguous `vendor or remote` label', function (): void {
    // Regression for the 0.7.2 label-split work. A host-only project
    // (no vendor/remote skills) must not show the legacy combined
    // string anywhere in its output.
    $dir = whereTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        whereHostSkill($dir, 'deploy');
        $result = runWhere($dir);
        expect($result['display'])->not->toContain('vendor or remote');
    } finally {
        whereCleanup($dir);
    }
});
