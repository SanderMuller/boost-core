<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\WrapperEntryPoints;

/**
 * Write a fake installed package whose composer.json carries `$extra`.
 *
 * @param  array<mixed>  $extra
 */
function entryPointPackage(string $dir, string $name, array $extra): PackageInfo
{
    $installPath = $dir . '/' . str_replace('/', '__', $name);
    mkdir($installPath, 0o755, recursive: true);
    file_put_contents(
        $installPath . '/composer.json',
        json_encode(['name' => $name] + $extra, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );

    return new PackageInfo($name, '1.0.0', $installPath);
}

function entryPointTempDir(): string
{
    $dir = sys_get_temp_dir() . '/boost-entrypoints-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

it('reads a declared entry-point map from a package composer.json', function (): void {
    $dir = entryPointTempDir();

    try {
        $package = entryPointPackage($dir, 'acme/wrapper', ['extra' => ['boost' => ['entry-point' => [
            'sync' => 'php artisan acme:sync',
            'where' => 'php artisan acme:where',
        ]]]]);

        $discovered = (new WrapperEntryPoints(new InstalledPackages(['acme/wrapper' => $package])))->discover();

        expect($discovered->covers('sync'))->toBeTrue()
            ->and($discovered->invocationFor('sync'))->toBe('php artisan acme:sync')
            ->and($discovered->packageFor('sync'))->toBe('acme/wrapper')
            ->and($discovered->covers('scan'))->toBeFalse()
            ->and($discovered->hasWrapper())->toBeTrue();
    } finally {
        cleanupTestDir($dir);
    }
});

it('reports no wrapper when nothing declares an entry point', function (): void {
    $dir = entryPointTempDir();

    try {
        $package = entryPointPackage($dir, 'acme/plain', ['extra' => ['boost' => ['skills' => 'res/skills']]]);

        $discovered = (new WrapperEntryPoints(new InstalledPackages(['acme/plain' => $package])))->discover();

        expect($discovered->hasWrapper())->toBeFalse()
            ->and($discovered->covers('sync'))->toBeFalse();
    } finally {
        cleanupTestDir($dir);
    }
});

it('ignores a doctor claim and records it for the doctor report', function (): void {
    // `doctor` is reserved: it diagnoses a broken wrapper and tells a misrouted
    // operator they are misrouted, so a wrapper must not be able to take it
    // away. The ignored claim is recorded, not printed here — it is a wrapper
    // author's bug and surfaces in `doctor` alone, never as per-run noise for
    // their users.
    $dir = entryPointTempDir();

    try {
        $package = entryPointPackage($dir, 'acme/wrapper', ['extra' => ['boost' => ['entry-point' => [
            'doctor' => 'php artisan acme:doctor',
            'sync' => 'php artisan acme:sync',
        ]]]]);

        $discovered = (new WrapperEntryPoints(new InstalledPackages(['acme/wrapper' => $package])))->discover();

        expect($discovered->covers('doctor'))->toBeFalse()
            ->and($discovered->covers('sync'))->toBeTrue()
            ->and($discovered->reservedClaims())->toBe(['acme/wrapper' => ['doctor']]);
    } finally {
        cleanupTestDir($dir);
    }
});

it('skips malformed entries without failing the run', function (): void {
    // A wrapper's composer.json is third-party data. A broken declaration must
    // never break the CLI it is describing.
    $dir = entryPointTempDir();

    try {
        $packages = [
            'acme/bad-shape' => entryPointPackage($dir, 'acme/bad-shape', ['extra' => ['boost' => ['entry-point' => 'nope']]]),
            'acme/bad-values' => entryPointPackage($dir, 'acme/bad-values', ['extra' => ['boost' => ['entry-point' => [
                'sync' => ['not', 'a', 'string'],
                'where' => '',
                'install' => 'php artisan acme:install',
            ]]]]),
        ];

        $discovered = (new WrapperEntryPoints(new InstalledPackages($packages)))->discover();

        expect($discovered->covers('sync'))->toBeFalse()
            ->and($discovered->covers('where'))->toBeFalse()
            ->and($discovered->invocationFor('install'))->toBe('php artisan acme:install');
    } finally {
        cleanupTestDir($dir);
    }
});

it('survives a package with no composer.json or unreadable JSON', function (): void {
    $dir = entryPointTempDir();

    try {
        $missing = new PackageInfo('acme/missing', '1.0.0', $dir . '/does-not-exist');

        $brokenPath = $dir . '/broken';
        mkdir($brokenPath, 0o755, recursive: true);
        file_put_contents($brokenPath . '/composer.json', '{ not json');

        $packages = [
            'acme/missing' => $missing,
            'acme/broken' => new PackageInfo('acme/broken', '1.0.0', $brokenPath),
        ];

        expect((new WrapperEntryPoints(new InstalledPackages($packages)))->discover()->hasWrapper())->toBeFalse();
    } finally {
        cleanupTestDir($dir);
    }
});

it('does not call a package a wrapper on the strength of a rejected claim', function (): void {
    // A package declaring ONLY the reserved `doctor` has had its one claim
    // dropped, so nothing is known about whether it extends the resolution
    // pipeline. Treating it as a wrapper made the gate assert that uncovered
    // pipeline commands "return an incomplete result" — a confident statement
    // resting on a claim boost-core refused to honour.
    $dir = entryPointTempDir();

    try {
        $package = entryPointPackage($dir, 'acme/doctor-only', ['extra' => ['boost' => ['entry-point' => [
            'doctor' => 'php artisan acme:doctor',
        ]]]]);

        $discovered = (new WrapperEntryPoints(new InstalledPackages(['acme/doctor-only' => $package])))->discover();

        expect($discovered->hasWrapper())->toBeFalse()
            ->and($discovered->reservedClaims())->toBe(['acme/doctor-only' => ['doctor']]);
    } finally {
        cleanupTestDir($dir);
    }
});

it('records a claim two wrappers make on the same command', function (): void {
    // First declaration wins, which is the only defensible resolution — but it
    // was silent, so the advisory named one wrapper's invocation arbitrarily
    // and the project looked correctly configured. The conflict is recorded so
    // `boost doctor` can say which command is contested.
    $dir = entryPointTempDir();

    try {
        $packages = [
            'acme/first' => entryPointPackage($dir, 'acme/first', ['extra' => ['boost' => ['entry-point' => [
                'sync' => 'php artisan first:sync',
            ]]]]),
            'acme/second' => entryPointPackage($dir, 'acme/second', ['extra' => ['boost' => ['entry-point' => [
                'sync' => 'php artisan second:sync',
            ]]]]),
        ];

        $discovered = (new WrapperEntryPoints(new InstalledPackages($packages)))->discover();

        expect($discovered->invocationFor('sync'))->toBe('php artisan first:sync')
            ->and($discovered->conflictingClaims())->toBe(['sync' => ['acme/second']]);
    } finally {
        cleanupTestDir($dir);
    }
});
