<?php declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\AvailableTagsDiscovery;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

function tagDiscoveryFixture(callable $body): void
{
    $vendorDir = sys_get_temp_dir() . '/boost-tagd-' . bin2hex(random_bytes(8));
    mkdir($vendorDir . '/resources/boost/skills', 0o755, recursive: true);
    file_put_contents($vendorDir . '/composer.json', json_encode(['name' => 'acme/skills'], JSON_THROW_ON_ERROR));

    try {
        $body($vendorDir);
    } finally {
        if (! is_dir($vendorDir)) {
            return;
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendorDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($iter as $f) {
            $path = $f->getPathname();
            $f->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($vendorDir);
    }
}

function tagDiscoverySkill(string $vendorDir, string $name, string $tags): void
{
    $dir = $vendorDir . '/resources/boost/skills/' . $name;
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/SKILL.md',
        "---\nname: {$name}\ndescription: Test.\nmetadata:\n  boost-tags: \"{$tags}\"\n---\n\nBody.\n",
    );
}

it("discovers the union of tags declared by selected vendors' skills, with unlock counts", function (): void {
    tagDiscoveryFixture(function (string $vendorDir): void {
        tagDiscoverySkill($vendorDir, 'jira-triage', 'php jira');
        tagDiscoverySkill($vendorDir, 'github-issues-triage', 'php github');
        tagDiscoverySkill($vendorDir, 'untagged-skill', '');

        $packages = new InstalledPackages([
            'acme/skills' => new PackageInfo('acme/skills', '1.0.0', $vendorDir),
        ]);

        $tags = (new AvailableTagsDiscovery($packages))->discover(['acme/skills']);

        // Two skills carry `php`, one each carries `jira` + `github`.
        // Untagged skill contributes nothing.
        expect($tags)->toBe(['github' => 1, 'jira' => 1, 'php' => 2]);
    });
});

it('returns empty when no vendors are passed', function (): void {
    $packages = new InstalledPackages([]);
    expect((new AvailableTagsDiscovery($packages))->discover([]))
        ->toBeEmpty();
});

it('ignores skills from vendors not in the allowlist', function (): void {
    tagDiscoveryFixture(function (string $vendorDir): void {
        tagDiscoverySkill($vendorDir, 'tagged-skill', 'php');

        $packages = new InstalledPackages([
            'acme/skills' => new PackageInfo('acme/skills', '1.0.0', $vendorDir),
        ]);

        // Allowlist contains a different vendor → discovered tag set is empty.
        expect((new AvailableTagsDiscovery($packages))->discover(['unrelated/pkg']))
            ->toBeEmpty();
    });
});
