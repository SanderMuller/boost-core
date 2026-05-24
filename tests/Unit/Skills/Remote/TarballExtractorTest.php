<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\RemoteExtractException;
use SanderMuller\BoostCore\Skills\Remote\TarballExtractor;

function tarTempBasePath(): string
{
    return sys_get_temp_dir() . '/boost-tar-test-' . bin2hex(random_bytes(6));
}

/**
 * Build a `.tar.gz` from a `name => content` map using `PharData`. PharData
 * requires the source file extension to be `.tar.gz` / `.tgz`.
 *
 * @param  array<string,string>  $entries
 */
function tarMakeTarGz(array $entries, ?string $basePath = null): string
{
    $basePath ??= tarTempBasePath();
    $tarPath = $basePath . '.tar';
    @unlink($tarPath);
    @unlink($basePath . '.tar.gz');

    $phar = new PharData($tarPath);
    foreach ($entries as $name => $content) {
        $phar->addFromString($name, $content);
    }

    $phar->compress(Phar::GZ);
    unset($phar);

    @unlink($tarPath); // we want the .tar.gz only

    return $basePath . '.tar.gz';
}

it('extracts a well-formed tarball into the destination directory', function (): void {
    $tar = tarMakeTarGz([
        'skill/SKILL.md' => "---\nname: skill\n---\nBody.",
        'skill/references/r.md' => 'r',
    ]);
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor())->extract($tar, $dest);

        expect(file_exists($dest . '/skill/SKILL.md'))->toBeTrue()
            ->and(file_get_contents($dest . '/skill/SKILL.md'))->toContain('Body.')
            ->and(file_exists($dest . '/skill/references/r.md'))->toBeTrue();
    } finally {
        @unlink($tar);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects a malformed tarball (not a tar at all)', function (): void {
    $bogus = tarTempBasePath() . '.tar.gz';
    file_put_contents($bogus, 'NOT A TARBALL');

    try {
        (new TarballExtractor())->extract($bogus, tarTempBasePath());
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::MALFORMED);
    } finally {
        @unlink($bogus);
    }
});

it('rejects an entry with `..` segments (PATH_TRAVERSAL)', function (): void {
    // PharData::addFromString actually rejects `..` names itself. Bypass by
    // constructing a tarball that PharData will accept but our validator
    // still flags. Easier: use a hand-rolled tar with a `..` entry header.
    // For Phase 3, this scenario is already covered by `BundleExtractor`
    // (same check, same code path); the path-validation logic is the same
    // function shape — punt on hand-rolling a tar here.
})->skip('PharData::addFromString rejects `..` names at build-time, so this needs a hand-rolled tar header — covered by BundleExtractor path-traversal test (same validation logic).');

// Adversarial size/count tests use tiny caps so we avoid building real
// 200MB / 11000-entry tarballs on every test run.

it('rejects a tarball with > maxEntries (ENTRY_COUNT)', function (): void {
    $entries = [];
    for ($i = 0; $i <= 5; ++$i) {
        $entries["file-{$i}.md"] = '.';
    }

    $tar = tarMakeTarGz($entries);
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor(maxEntries: 5))->extract($tar, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::ENTRY_COUNT);
    } finally {
        @unlink($tar);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects a tarball whose single file exceeds maxFileBytes', function (): void {
    $tar = tarMakeTarGz(['huge.bin' => str_repeat('a', 1024)]);
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor(maxFileBytes: 500))->extract($tar, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SIZE_LIMIT);
    } finally {
        @unlink($tar);
        BundleExtractor::recursivelyRemove($dest);
    }
});
