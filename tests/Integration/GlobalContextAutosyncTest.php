<?php

declare(strict_types=1);

/**
 * Verifies BoostCorePlugin auto-syncs user-scope skills when invoked
 * via `composer global require <skill-bearing-package>`.
 *
 * Setup mirrors a real `composer global` invocation: a tmp COMPOSER_HOME
 * with its own composer.json acting as the "root" project, the package
 * under test installed there, and HOME overridden to a tmp dir so the
 * sync writes don't touch the developer's real ~/.claude/ etc.
 */
function rmDirRecursiveGlobal(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . '/' . $item;
        is_dir($full) && ! is_link($full) ? rmDirRecursiveGlobal($full) : @unlink($full);
    }
    @rmdir($path);
}

it('auto-syncs user-scope skills for global-required packages', function () {
    $packageRoot = dirname(__DIR__, 2);
    $base = sys_get_temp_dir() . '/boost-global-' . bin2hex(random_bytes(6));
    $composerHome = $base . '/composer-home';
    $fakeHome = $base . '/home';
    $fakePackageSrc = $base . '/fake-skill-pkg';

    mkdir($composerHome, 0o755, recursive: true);
    mkdir($fakeHome, 0o755, recursive: true);
    mkdir($fakePackageSrc . '/resources/boost/skills', 0o755, recursive: true);

    // Fake third-party package with one user-scope skill.
    file_put_contents($fakePackageSrc . '/composer.json', json_encode([
        'name' => 'acme/global-skill-pkg',
        'description' => 'Test fixture',
        'type' => 'library',
    ], JSON_PRETTY_PRINT));

    file_put_contents(
        $fakePackageSrc . '/resources/boost/skills/hello.md',
        "---\nname: hello\ndescription: Fixture skill\n---\n\nHello from the fixture.\n",
    );

    // Global root: requires boost-core (the plugin) + the fake skill pkg.
    file_put_contents($composerHome . '/composer.json', json_encode([
        'name' => 'boost-core-test/global-fixture-root',
        'require' => [
            'sandermuller/boost-core' => '*',
            'acme/global-skill-pkg' => '*',
        ],
        'repositories' => [
            [
                'type' => 'path',
                'url' => $packageRoot,
                'options' => ['symlink' => false],
            ],
            [
                'type' => 'path',
                'url' => $fakePackageSrc,
                'options' => ['symlink' => false],
            ],
        ],
        'minimum-stability' => 'dev',
        'config' => [
            'allow-plugins' => [
                'sandermuller/boost-core' => true,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Simulate `composer global install`: cwd = COMPOSER_HOME, argv contains "global".
    // We can't easily set $_SERVER['argv'] inside the child composer process, so we
    // exec composer via a wrapper that prefixes "global" to argv. Simplest reliable
    // path: invoke `composer global install` directly — composer's own runtime will
    // set cwd to COMPOSER_HOME and put `global` in argv.
    $output = [];
    $exit = 0;
    $cmd = sprintf(
        'COMPOSER_HOME=%s HOME=%s composer global install --no-interaction 2>&1',
        escapeshellarg($composerHome),
        escapeshellarg($fakeHome),
    );
    exec($cmd, $output, $exit);
    $outputStr = implode("\n", $output);

    if ($exit !== 0) {
        rmDirRecursiveGlobal($base);
        $this->fail("composer global install failed (exit $exit):\n" . $outputStr);
    }

    // Plugin should have fanned the fixture skill into every agent's home dir.
    // Spot-check Claude Code's target: $HOME/.claude/skills/global-skill-pkg/hello.md
    $expected = $fakeHome . '/.claude/skills/global-skill-pkg/hello.md';

    $exists = is_file($expected);
    $contents = $exists ? (string) file_get_contents($expected) : '';

    rmDirRecursiveGlobal($base);

    expect($exists)->toBeTrue('expected ' . $expected . ' to exist. Composer output:' . PHP_EOL . $outputStr);
    expect($contents)->toContain('Hello from the fixture.');
});

it('warns and skips on basename collision in global ctx', function () {
    $packageRoot = dirname(__DIR__, 2);
    $base = sys_get_temp_dir() . '/boost-global-collide-' . bin2hex(random_bytes(6));
    $composerHome = $base . '/composer-home';
    $fakeHome = $base . '/home';
    $pkgA = $base . '/pkg-a';
    $pkgB = $base . '/pkg-b';

    mkdir($composerHome, 0o755, recursive: true);
    mkdir($fakeHome, 0o755, recursive: true);
    mkdir($pkgA . '/resources/boost/skills', 0o755, recursive: true);
    mkdir($pkgB . '/resources/boost/skills', 0o755, recursive: true);

    // Two distinct packages sharing the basename `dup-tool` — should collide.
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

    $output = [];
    $exit = 0;
    exec(
        sprintf(
            'COMPOSER_HOME=%s HOME=%s composer global install --no-interaction 2>&1',
            escapeshellarg($composerHome),
            escapeshellarg($fakeHome),
        ),
        $output,
        $exit,
    );
    $outputStr = implode("\n", $output);

    if ($exit !== 0) {
        rmDirRecursiveGlobal($base);
        $this->fail("composer global install failed (exit $exit):\n" . $outputStr);
    }

    // Whichever package composer installed first claimed the suffix; the
    // other should be skipped with a warning. We don't pin the order — just
    // that exactly one of the skills landed and a collision warning was
    // emitted naming the loser.
    $targetDir = $fakeHome . '/.claude/skills/dup-tool';
    $hasA = is_file($targetDir . '/from-a.md');
    $hasB = is_file($targetDir . '/from-b.md');

    rmDirRecursiveGlobal($base);

    expect($hasA xor $hasB)->toBeTrue(
        sprintf('expected exactly one of from-a.md or from-b.md (a=%d b=%d). Output:%s%s', (int) $hasA, (int) $hasB, PHP_EOL, $outputStr),
    );
    expect($outputStr)
        ->toContain('basename "dup-tool" already claimed')
        ->toContain('skipping global auto-sync');
});
