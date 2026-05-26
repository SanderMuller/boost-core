<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;

function shadowFixture(callable $body): void
{
    $root = sys_get_temp_dir() . '/boost-shadow-' . bin2hex(random_bytes(8));
    $vendorDir = sys_get_temp_dir() . '/boost-shadow-vendor-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/skills', 0o755, recursive: true);
    mkdir($vendorDir . '/resources/boost/skills', 0o755, recursive: true);
    file_put_contents($vendorDir . '/composer.json', json_encode(['name' => 'acme/skills'], JSON_THROW_ON_ERROR));

    try {
        $body($root, $vendorDir);
    } finally {
        foreach ([$root, $vendorDir] as $dir) {
            if (! is_dir($dir)) {
                continue;
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
    }
}

function shadowWriteSkill(string $dir, string $name, string $body): string
{
    $skillDir = $dir . '/' . $name;
    if (! is_dir($skillDir)) {
        mkdir($skillDir, 0o755, recursive: true);
    }

    $path = $skillDir . '/SKILL.md';
    file_put_contents($path, "---\nname: {$name}\ndescription: Test.\n---\n\n{$body}");

    return $path;
}

it('resolveSkillShadowPaths: returns host + vendor paths when both exist (the happy path `--diff` walks)', function (): void {
    shadowFixture(function (string $root, string $vendorDir): void {
        $hostPath = shadowWriteSkill($root . '/.ai/skills', 'deploy', 'host body');
        $vendorPath = shadowWriteSkill($vendorDir . '/resources/boost/skills', 'deploy', 'vendor body');

        file_put_contents(
            $root . '/boost.php',
            "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/skills']);\n",
        );

        $packages = new InstalledPackages([
            'acme/skills' => new PackageInfo('acme/skills', '1.0.0', $vendorDir),
        ]);

        $engine = new SyncEngine(agentTargets: [new ClaudeCodeTarget()], installedPackages: $packages);
        $paths = $engine->resolveSkillShadowPaths($root, 'deploy');

        // SkillLoader resolves through realpath() (macOS `/var/folders` is a
        // symlink to `/private/var/folders` so the returned path can have
        // the `/private` prefix). Compare canonical forms either way.
        expect($paths)->not->toBeNull();
        assert($paths !== null);
        expect($paths['hostPath'])->toBe(realpath($hostPath))
            ->and($paths['vendorPath'])->toBe(realpath($vendorPath))
            ->and($paths['vendor'])->toBe('acme/skills');
    });
});

it('resolveSkillShadowPaths: returns null when the host skill does not exist', function (): void {
    shadowFixture(function (string $root, string $vendorDir): void {
        shadowWriteSkill($vendorDir . '/resources/boost/skills', 'deploy', 'vendor body');

        file_put_contents(
            $root . '/boost.php',
            "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/skills']);\n",
        );

        $packages = new InstalledPackages([
            'acme/skills' => new PackageInfo('acme/skills', '1.0.0', $vendorDir),
        ]);

        $engine = new SyncEngine(agentTargets: [new ClaudeCodeTarget()], installedPackages: $packages);
        expect($engine->resolveSkillShadowPaths($root, 'deploy'))->toBeNull();
    });
});

it('resolveSkillShadowPaths: returns null when no allowlisted vendor publishes the skill', function (): void {
    shadowFixture(function (string $root, string $vendorDir): void {
        shadowWriteSkill($root . '/.ai/skills', 'deploy', 'host body');

        file_put_contents(
            $root . '/boost.php',
            "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/skills']);\n",
        );

        $packages = new InstalledPackages([
            'acme/skills' => new PackageInfo('acme/skills', '1.0.0', $vendorDir),
        ]);

        // Vendor exists but publishes no `deploy` skill — host is unique → not a shadow.
        $engine = new SyncEngine(agentTargets: [new ClaudeCodeTarget()], installedPackages: $packages);
        expect($engine->resolveSkillShadowPaths($root, 'deploy'))->toBeNull();
    });
});
