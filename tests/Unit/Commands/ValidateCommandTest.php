<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\ValidateCommand;
use Symfony\Component\Console\Tester\CommandTester;

function validateTempProject(string $boostBody): string
{
    $dir = sys_get_temp_dir() . '/boost-validate-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn {$boostBody};\n",
    );

    return $dir;
}

function validateCleanup(string $dir): void
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
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

/**
 * @param  array<string, bool|string>  $options
 * @return array{exit: int, display: string}
 */
function runValidate(string $dir, array $options = []): array
{
    $command = new ValidateCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $exit = $tester->execute(['--working-dir' => $dir, ...$options]);

    return ['exit' => $exit, 'display' => $tester->getDisplay()];
}

it('0.16.0 leak gate: --strict fails on a leaked conventions token in emitted output', function (): void {
    $dir = validateTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\n```yaml boost:conv\npatterns:\n  - main\n```\n");

        $result = runValidate($dir, ['--strict' => true]);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';

        expect($result['exit'])->toBe(1)
            ->and($display)->toContain('leaked conventions token')
            ->and($display)->toContain('CLAUDE.md');
    } finally {
        validateCleanup($dir);
    }
});

it('0.16.0 leak gate: clean emitted output passes --strict', function (): void {
    $dir = validateTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\nNo tokens here.\n");

        $result = runValidate($dir, ['--strict' => true]);

        expect($result['exit'])->toBe(0);
    } finally {
        validateCleanup($dir);
    }
});

it('0.16.0 leak gate: reports the leak but stays exit 0 WITHOUT --strict (advisory)', function (): void {
    $dir = validateTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\n```yaml boost:conv\npatterns:\n  - main\n```\n");

        $result = runValidate($dir);
        $display = preg_replace('/\s+/', ' ', $result['display']) ?? '';

        expect($result['exit'])->toBe(0)
            ->and($display)->toContain('leaked conventions token');
    } finally {
        validateCleanup($dir);
    }
});

it('0.16.0 leak gate: emits leak diagnostics in --json output', function (): void {
    $dir = validateTempProject('BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])');
    try {
        file_put_contents($dir . '/CLAUDE.md', "# Project\n\n```yaml boost:conv\npatterns:\n  - main\n```\n");

        $result = runValidate($dir, ['--json' => true]);
        /** @var array{diagnostics: list<array{message: string}>} $decoded */
        $decoded = json_decode(trim($result['display']), true, 512, JSON_THROW_ON_ERROR);

        $messages = array_map(static fn (array $d): string => $d['message'], $decoded['diagnostics']);
        expect(implode("\n", $messages))->toContain('leaked conventions token');
    } finally {
        validateCleanup($dir);
    }
});
