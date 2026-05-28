<?php declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\ConvertConventionsCommand;
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use Symfony\Component\Console\Tester\CommandTester;

function convertTempProject(string $boostReturn = 'BoostConfig::configure()'): string
{
    $dir = sys_get_temp_dir() . '/boost-convert-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nreturn {$boostReturn};\n",
    );

    return $dir;
}

function convertCleanup(string $dir): void
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

function writeClaudeMd(string $dir, string $body, string $outsidePrefix = '', string $outsideSuffix = ''): void
{
    $explainer = ConventionsBlockEmitter::EXPLAINER;
    $start = ConventionsBlockEmitter::START_MARKER;
    $end = ConventionsBlockEmitter::END_MARKER;
    $region = $explainer . "\n" . $start . "\n" . $body . "\n" . $end;
    $claudeMd = ($outsidePrefix === '' ? '' : $outsidePrefix . "\n") . $region . ($outsideSuffix === '' ? '' : "\n" . $outsideSuffix);
    file_put_contents($dir . '/CLAUDE.md', $claudeMd);
}

/**
 * @return array{exit: int, display: string}
 */
function runConvert(string $dir, bool $dryRun = false, bool $keepBlock = false): array
{
    $command = new ConvertConventionsCommand();
    $app = new ComposerApplication();
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $input = ['--working-dir' => $dir];
    if ($dryRun) {
        $input['--dry-run'] = true;
    }

    if ($keepBlock) {
        $input['--keep-block'] = true;
    }

    $exit = $tester->execute($input);

    return ['exit' => $exit, 'display' => $tester->getDisplay()];
}

it('succeeds with a no-op message when CLAUDE.md is absent', function (): void {
    $dir = convertTempProject();
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('no CLAUDE.md found');
    } finally {
        convertCleanup($dir);
    }
});

it('succeeds with a no-op message when CLAUDE.md has no marker region', function (): void {
    $dir = convertTempProject();
    file_put_contents($dir . '/CLAUDE.md', "# Project\n\nSome random prose, no markers.\n");
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('no Project Conventions marker region');
    } finally {
        convertCleanup($dir);
    }
});

it('succeeds with a no-op message when the marker body is scaffold-only (schema-version only)', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\nschema-version: 1\n```");
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('empty (no operator-filled values to migrate)');
    } finally {
        convertCleanup($dir);
    }
});

it('fails when both boost.php->withConventions and CLAUDE.md body are filled (two-source conflict)', function (): void {
    $dir = convertTempProject("BoostConfig::configure()->withConventions(['jira' => ['project_key' => 'XYZ']])");
    writeClaudeMd($dir, "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```");
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(1)
            ->and($result['display'])->toContain('boost.php already declares ->withConventions')
            ->and($result['display'])->toContain('Refusing to overwrite');
    } finally {
        convertCleanup($dir);
    }
});

it('fails with a parse-error diagnostic when CLAUDE.md marker body is malformed YAML', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\njira:\n  project_key: HPB\n   bad_indent: nope\n```");
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(1)
            ->and($result['display'])->toContain('YAML parse failure');
    } finally {
        convertCleanup($dir);
    }
});

it('--dry-run prints the rewritten boost.php and writes nothing to disk', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n  refuse_other_projects: true\n```");
    $boostBefore = (string) file_get_contents($dir . '/boost.php');
    $claudeBefore = (string) file_get_contents($dir . '/CLAUDE.md');
    try {
        $result = runConvert($dir, dryRun: true);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Preview')
            ->and($result['display'])->toContain("'project_key' => 'HPB'")
            ->and((string) file_get_contents($dir . '/boost.php'))->toBe($boostBefore)
            ->and((string) file_get_contents($dir . '/CLAUDE.md'))->toBe($claudeBefore);
    } finally {
        convertCleanup($dir);
    }
});

it('writes ->withConventions([...]) into boost.php and clears CLAUDE.md marker body', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```");
    try {
        $result = runConvert($dir);

        $newBoost = (string) file_get_contents($dir . '/boost.php');
        $newClaude = (string) file_get_contents($dir . '/CLAUDE.md');

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('Migrated 1 slot group')
            ->and($newBoost)->toContain('withConventions')
            ->and($newBoost)->toContain("'project_key' => 'HPB'")
            ->and($newBoost)->not->toContain('schema-version')
            ->and($newClaude)->toContain(ConventionsBlockEmitter::START_MARKER)
            ->and($newClaude)->toContain(ConventionsBlockEmitter::END_MARKER)
            ->and($newClaude)->not->toContain('project_key: HPB');
    } finally {
        convertCleanup($dir);
    }
});

it('--keep-block leaves CLAUDE.md marker body intact so next sync detects the two-source conflict', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```");
    try {
        $result = runConvert($dir, keepBlock: true);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->toContain('--keep-block set')
            ->and((string) file_get_contents($dir . '/CLAUDE.md'))->toContain('project_key: HPB');
    } finally {
        convertCleanup($dir);
    }
});

it('preserves operator-authored prose outside the marker region (the data-loss footgun §3.5 protects against)', function (): void {
    $dir = convertTempProject();
    writeClaudeMd(
        $dir,
        "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```",
        outsidePrefix: "# My Project\n\nThis is custom operator-authored prose above the conventions region.\n## Project Conventions",
        outsideSuffix: "## Custom section below\n\nMore operator prose the engine must not touch.\n",
    );
    try {
        $result = runConvert($dir);

        $claudeAfter = (string) file_get_contents($dir . '/CLAUDE.md');

        expect($result['exit'])->toBe(0)
            ->and($claudeAfter)->toContain('# My Project')
            ->and($claudeAfter)->toContain('custom operator-authored prose above')
            ->and($claudeAfter)->toContain('## Custom section below')
            ->and($claudeAfter)->toContain('More operator prose the engine must not touch.');
    } finally {
        convertCleanup($dir);
    }
});

it('writes next-step guidance WITHOUT the reverted `git rm --cached` instruction (§3.5 revert holds)', function (): void {
    $dir = convertTempProject();
    writeClaudeMd($dir, "```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```");
    try {
        $result = runConvert($dir);

        expect($result['exit'])->toBe(0)
            ->and($result['display'])->not->toContain('git rm --cached')
            ->and($result['display'])->toContain('CLAUDE.md stays tracked');
    } finally {
        convertCleanup($dir);
    }
});
