<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\TagReporter;
use SanderMuller\BoostCore\Commands\TagsCommand;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use Symfony\Component\Console\Tester\CommandTester;

function tagsTempProject(bool $withConfig = true): string
{
    $dir = sys_get_temp_dir() . '/boost-tags-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    if ($withConfig) {
        file_put_contents(
            $dir . '/boost.php',
            "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nreturn BoostConfig::configure();\n",
        );
    }

    return $dir;
}

function tagsCleanup(string $dir): void
{
    if (is_file($dir . '/boost.php')) {
        unlink($dir . '/boost.php');
    }

    if (is_dir($dir)) {
        rmdir($dir);
    }
}

/**
 * @return array{exit: int, display: string}
 */
function runTags(string $dir): array
{
    $command = new TagsCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute(['--working-dir' => $dir]);

    return ['exit' => $exit, 'display' => $tester->getDisplay()];
}

it('renders the tag report for a project with no declared tags', function (): void {
    $dir = tagsTempProject();
    try {
        $result = runTags($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('boost-core tags')
            ->and($result['display'])->toContain('No tags declared');
    } finally {
        tagsCleanup($dir);
    }
});

it('fails when boost.php is missing', function (): void {
    $dir = tagsTempProject(withConfig: false);
    try {
        $result = runTags($dir);

        expect($result['exit'])->toBe(1);
    } finally {
        tagsCleanup($dir);
    }
});

it('0.10.0 diagnostic split case 3: declared-but-unused tags + project-boost-laravel installed → emits cross-agent-asymmetry hint with artisan path', function (): void {
    $dir = tagsTempProject();
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withTags(['php', 'filament', 'livewire']);\n",
    );
    $sibling = sys_get_temp_dir() . '/sibling-pbl-tags-' . bin2hex(random_bytes(8));
    mkdir($sibling, 0o755, recursive: true);

    try {
        $packages = new InstalledPackages([
            'sandermuller/project-boost-laravel' => new PackageInfo(
                name: 'sandermuller/project-boost-laravel',
                version: '0.3.6',
                installPath: $sibling,
            ),
        ]);
        $reporter = new TagReporter(injectedPackages: $packages);
        $command = new TagsCommand(reporter: $reporter);
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir]);

        // Strip Symfony hard-wraps to defend against display-width drift.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        expect($exit)->toBe(0)
            ->and($display)->toContain('project-boost-laravel detected')
            ->and($display)->toContain('php artisan project-boost:sync')
            ->and($display)->toContain('cross-agent capability asymmetry')
            // Forward-compat wording MUST NOT fire when case-3 is the
            // detected probable root cause.
            ->and($display)->not->toContain('forward-compat declaration');
    } finally {
        tagsCleanup($dir);
        @rmdir($sibling);
    }
});

it('0.10.0 diagnostic split case 2: declared-but-unused tags + project-boost-laravel NOT installed → emits forward-compat hint (no bare-CLI claim)', function (): void {
    $dir = tagsTempProject();
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withTags(['php', 'future-only-tag']);\n",
    );

    try {
        $packages = new InstalledPackages([]);
        $reporter = new TagReporter(injectedPackages: $packages);
        $command = new TagsCommand(reporter: $reporter);
        $app = new ComposerApplication();
        $app->addCommand($command);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--working-dir' => $dir]);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        expect($exit)->toBe(0)
            // The default "no allowlisted vendor skills or guidelines installed"
            // wording fires (legitimate empty-allowlist case).
            ->and($display)->toContain('No allowlisted vendor skills or guidelines installed')
            // Cross-agent + artisan wording MUST NOT fire when no wrapper detected.
            // Critical contrast against case-3: same empty-skills state, different
            // recommendation depending on wrapper-presence detection.
            ->and($display)->not->toContain('cross-agent capability asymmetry')
            ->and($display)->not->toContain('php artisan project-boost:sync')
            ->and($display)->not->toContain('project-boost-laravel detected');
    } finally {
        tagsCleanup($dir);
    }
});
