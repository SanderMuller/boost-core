<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\TagsCommand;
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
