<?php declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\PathRepoDetector;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

function pathRepoFixture(callable $body): void
{
    $root = sys_get_temp_dir() . '/boost-path-repo-' . bin2hex(random_bytes(8));
    mkdir($root . '/vendor', 0o755, recursive: true);
    try {
        $body($root);
    } finally {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($iter as $f) {
            $path = $f->getPathname();
            $f->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($root);
    }
}

it('flags a family package whose install path is outside the project vendor/', function (): void {
    pathRepoFixture(function (string $root): void {
        // Sibling layout: ../boost-core lives next to the project root.
        $sibling = dirname($root) . '/sibling-boost-core-' . bin2hex(random_bytes(4));
        mkdir($sibling, 0o755, recursive: true);
        try {
            $packages = new InstalledPackages([
                'sandermuller/boost-core' => new PackageInfo(
                    name: 'sandermuller/boost-core',
                    version: 'dev-main',
                    installPath: $sibling,
                ),
            ]);

            $detector = new PathRepoDetector($packages);
            expect($detector->findShadowingPackages($root))->toBe(['sandermuller/boost-core']);
        } finally {
            @rmdir($sibling);
        }
    });
});

it('does not flag a package installed at the routine vendor/<vendor>/<name> location', function (): void {
    pathRepoFixture(function (string $root): void {
        $vendorPath = $root . '/vendor/sandermuller/boost-core';
        mkdir($vendorPath, 0o755, recursive: true);

        $packages = new InstalledPackages([
            'sandermuller/boost-core' => new PackageInfo(
                name: 'sandermuller/boost-core',
                version: '0.7.1',
                installPath: $vendorPath,
            ),
        ]);

        $detector = new PathRepoDetector($packages);
        expect($detector->findShadowingPackages($root))
            ->toBeEmpty();
    });
});

it('ignores non-family packages even when they live outside vendor/', function (): void {
    pathRepoFixture(function (string $root): void {
        $sibling = dirname($root) . '/sibling-other-' . bin2hex(random_bytes(4));
        mkdir($sibling, 0o755, recursive: true);
        try {
            $packages = new InstalledPackages([
                'acme/unrelated' => new PackageInfo(
                    name: 'acme/unrelated',
                    version: 'dev-main',
                    installPath: $sibling,
                ),
            ]);

            $detector = new PathRepoDetector($packages);
            expect($detector->findShadowingPackages($root))
                ->toBeEmpty();
        } finally {
            @rmdir($sibling);
        }
    });
});

it('returns empty when the project vendor/ does not exist (fresh install with nothing yet)', function (): void {
    $root = sys_get_temp_dir() . '/boost-no-vendor-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, recursive: true);
    try {
        $detector = new PathRepoDetector(new InstalledPackages([]));
        expect($detector->findShadowingPackages($root))
            ->toBeEmpty();
    } finally {
        @rmdir($root);
    }
});
