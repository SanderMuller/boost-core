<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Verifies BoostCorePlugin auto-syncs user-scope skills when invoked
 * via `composer global require <skill-bearing-package>`. We override
 * COMPOSER_HOME and HOME to per-test tmp dirs so sync writes never
 * touch the developer's real ~/.claude/ etc.
 */
function runComposerGlobalInstall(string $composerHome, string $home): Process
{
    $process = Process::fromShellCommandline('composer global install --no-interaction 2>&1');
    $process->setEnv([
        'COMPOSER_HOME' => $composerHome,
        'HOME' => $home,
    ]);
    $process->setTimeout(180);
    $process->run();

    return $process;
}

it('auto-syncs user-scope skills for global-required packages', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $base = sys_get_temp_dir() . '/boost-global-' . bin2hex(random_bytes(6));
    $composerHome = $base . '/composer-home';
    $fakeHome = $base . '/home';
    $fakePackageSrc = $base . '/fake-skill-pkg';

    try {
        mkdir($composerHome, 0o755, recursive: true);
        mkdir($fakeHome, 0o755, recursive: true);
        mkdir($fakePackageSrc . '/resources/boost/skills', 0o755, recursive: true);

        file_put_contents($fakePackageSrc . '/composer.json', json_encode([
            'name' => 'acme/global-skill-pkg',
            'description' => 'Test fixture',
            'type' => 'library',
        ], JSON_PRETTY_PRINT));

        file_put_contents(
            $fakePackageSrc . '/resources/boost/skills/hello.md',
            "---\nname: hello\ndescription: Fixture skill\n---\n\nHello from the fixture.\n",
        );

        file_put_contents($composerHome . '/composer.json', json_encode([
            'name' => 'boost-core-test/global-fixture-root',
            'require' => [
                'sandermuller/boost-core' => '*',
                'acme/global-skill-pkg' => '*',
            ],
            'repositories' => [
                ['type' => 'path', 'url' => $packageRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $fakePackageSrc, 'options' => ['symlink' => false]],
            ],
            'minimum-stability' => 'dev',
            'config' => [
                'allow-plugins' => [
                    'sandermuller/boost-core' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = runComposerGlobalInstall($composerHome, $fakeHome);
        $outputStr = $process->getOutput() . $process->getErrorOutput();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("composer global install failed (exit {$process->getExitCode()}):\n" . $outputStr);
        }

        $expected = $fakeHome . '/.claude/skills/global-skill-pkg/hello/SKILL.md';

        expect($expected)
            ->toBeFile()
            ->and((string) file_get_contents($expected))
            ->toContain('Hello from the fixture.');
    } finally {
        cleanupTestDir($base);
    }
});

it('warns and skips on basename collision in global ctx', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $base = sys_get_temp_dir() . '/boost-global-collide-' . bin2hex(random_bytes(6));
    $composerHome = $base . '/composer-home';
    $fakeHome = $base . '/home';
    $pkgA = $base . '/pkg-a';
    $pkgB = $base . '/pkg-b';

    try {
        mkdir($composerHome, 0o755, recursive: true);
        mkdir($fakeHome, 0o755, recursive: true);
        mkdir($pkgA . '/resources/boost/skills', 0o755, recursive: true);
        mkdir($pkgB . '/resources/boost/skills', 0o755, recursive: true);

        file_put_contents($pkgA . '/composer.json', json_encode([
            'name' => 'vendor-a/dup-tool',
            'description' => 'Test fixture A',
            'type' => 'library',
        ], JSON_PRETTY_PRINT));
        file_put_contents(
            $pkgA . '/resources/boost/skills/from-a.md',
            "---\nname: from-a\ndescription: A\n---\n\nFrom A.\n",
        );

        file_put_contents($pkgB . '/composer.json', json_encode([
            'name' => 'vendor-b/dup-tool',
            'description' => 'Test fixture B',
            'type' => 'library',
        ], JSON_PRETTY_PRINT));
        file_put_contents(
            $pkgB . '/resources/boost/skills/from-b.md',
            "---\nname: from-b\ndescription: B\n---\n\nFrom B.\n",
        );

        file_put_contents($composerHome . '/composer.json', json_encode([
            'name' => 'boost-core-test/collision-fixture-root',
            'require' => [
                'sandermuller/boost-core' => '*',
                'vendor-a/dup-tool' => '*',
                'vendor-b/dup-tool' => '*',
            ],
            'repositories' => [
                ['type' => 'path', 'url' => $packageRoot, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $pkgA, 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => $pkgB, 'options' => ['symlink' => false]],
            ],
            'minimum-stability' => 'dev',
            'config' => [
                'allow-plugins' => [
                    'sandermuller/boost-core' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = runComposerGlobalInstall($composerHome, $fakeHome);
        $outputStr = $process->getOutput() . $process->getErrorOutput();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("composer global install failed (exit {$process->getExitCode()}):\n" . $outputStr);
        }

        // Install order isn't pinned; assert exactly one skill landed and
        // the loser was reported via the documented collision warning.
        $targetDir = $fakeHome . '/.claude/skills/dup-tool';
        $hasA = is_file($targetDir . '/from-a/SKILL.md');
        $hasB = is_file($targetDir . '/from-b/SKILL.md');

        expect($hasA xor $hasB)->toBeTrue(
            sprintf('expected exactly one of from-a/SKILL.md or from-b/SKILL.md (a=%d b=%d). Output:%s%s', (int) $hasA, (int) $hasB, PHP_EOL, $outputStr),
        )
            ->and($outputStr)
            ->toContain('basename "dup-tool" already claimed')
            ->toContain('skipping global auto-sync');
    } finally {
        cleanupTestDir($base);
    }
});
