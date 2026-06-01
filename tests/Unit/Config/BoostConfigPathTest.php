<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\AmbiguousBoostConfigException;
use SanderMuller\BoostCore\Config\BoostConfigPath;

function bcpTempProject(): string
{
    $dir = sys_get_temp_dir() . '/boost-cfgpath-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function bcpCleanup(string $dir): void
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

it('resolves root boost.php when only it exists', function (): void {
    $dir = bcpTempProject();
    file_put_contents($dir . '/boost.php', '<?php return null;');

    try {
        $resolved = BoostConfigPath::resolve($dir);
        expect($resolved->path)->toBe($dir . '/boost.php')
            ->and($resolved->exists)->toBeTrue()
            ->and($resolved->inConfigDir)->toBeFalse();
    } finally {
        bcpCleanup($dir);
    }
});

it('resolves .config/boost.php when only it exists', function (): void {
    $dir = bcpTempProject();
    mkdir($dir . '/.config', 0o755);
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');

    try {
        $resolved = BoostConfigPath::resolve($dir);
        expect($resolved->path)->toBe($dir . '/.config/boost.php')
            ->and($resolved->exists)->toBeTrue()
            ->and($resolved->inConfigDir)->toBeTrue();
    } finally {
        bcpCleanup($dir);
    }
});

it('throws Ambiguous when BOTH root and .config/ configs exist', function (): void {
    $dir = bcpTempProject();
    mkdir($dir . '/.config', 0o755);
    file_put_contents($dir . '/boost.php', '<?php return null;');
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');

    try {
        expect(fn (): BoostConfigPath => BoostConfigPath::resolve($dir))
            ->toThrow(AmbiguousBoostConfigException::class);
    } finally {
        bcpCleanup($dir);
    }
});

it('defaults to root (exists=false) when neither config is present', function (): void {
    $dir = bcpTempProject();

    try {
        $resolved = BoostConfigPath::resolve($dir);
        expect($resolved->path)->toBe($dir . '/boost.php')
            ->and($resolved->exists)->toBeFalse();
    } finally {
        bcpCleanup($dir);
    }
});

it('resolves a RELATIVE explicit path against the project root, not CWD', function (): void {
    $dir = bcpTempProject();
    mkdir($dir . '/.config', 0o755);
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');

    try {
        $resolved = BoostConfigPath::resolve($dir, '.config/boost.php');
        expect($resolved->path)->toBe($dir . '/.config/boost.php')
            ->and($resolved->exists)->toBeTrue();
    } finally {
        bcpCleanup($dir);
    }
});

it('uses an ABSOLUTE explicit path as-is, ignoring root/.config ambiguity', function (): void {
    $dir = bcpTempProject();
    mkdir($dir . '/.config', 0o755);
    // Both exist — but an explicit absolute path wins, no ambiguity error.
    file_put_contents($dir . '/boost.php', '<?php return null;');
    file_put_contents($dir . '/.config/boost.php', '<?php return null;');

    try {
        $resolved = BoostConfigPath::resolve($dir, $dir . '/boost.php');
        expect($resolved->path)->toBe($dir . '/boost.php')
            ->and($resolved->exists)->toBeTrue();
    } finally {
        bcpCleanup($dir);
    }
});
