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

/**
 * Absolute path to a committed adversarial fixture. These carry entries
 * (`..`, absolute path, symlink) that `PharData::addFromString` refuses to
 * build, so they were produced once with system `tar` — see
 * tests/Fixtures/tarballs/README.md for the exact build commands.
 */
function tarFixture(string $name): string
{
    return __DIR__ . '/../../../Fixtures/tarballs/' . $name;
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

it('rejects a `..` path-traversal entry (PATH_TRAVERSAL) before extracting', function (): void {
    // Discriminating: against the old extract-then-scan code bsdtar refused the
    // `..` member with a non-zero exit mapped to DISK_FULL — only the
    // pre-extraction `tar -tzf` name check yields PATH_TRAVERSAL.
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor())->extract(tarFixture('dotdot.tar.gz'), $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::PATH_TRAVERSAL)
            ->and(is_dir($dest))->toBeFalse();
    } finally {
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an absolute-path entry (ABSOLUTE_PATH) before extracting', function (): void {
    // Discriminating: the old code let bsdtar strip the leading `/` on extract,
    // so ABSOLUTE_PATH never fired — only the pre-extraction listing catches it.
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor())->extract(tarFixture('absolute.tar.gz'), $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::ABSOLUTE_PATH)
            ->and(is_dir($dest))->toBeFalse();
    } finally {
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects a symlink member (SYMLINK) and writes nothing to the destination', function (): void {
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor())->extract(tarFixture('symlink.tar.gz'), $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SYMLINK)
            ->and(is_dir($dest))->toBeFalse();
    } finally {
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects a symlink member in the PRE-extraction listing (proves validate-before-extract)', function (): void {
    // Exercises assertArchiveSafe() directly, so it proves the `tar -tvzf`
    // symlink check fires BEFORE any extraction — not the post-extraction
    // staged-isLink() scan, which would also catch a lone symlink and so cannot
    // prove the reorder. This is the guard against the pre-check shipping dead.
    $method = new ReflectionMethod(TarballExtractor::class, 'assertArchiveSafe');

    try {
        $method->invoke(new TarballExtractor(), tarFixture('symlink.tar.gz'));
        throw new RuntimeException('Expected assertArchiveSafe to reject the symlink.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SYMLINK);
    }
});

it('rejects a tarball whose uncompressed total exceeds maxTotalBytes (SIZE_LIMIT)', function (): void {
    // Four 300-byte files = 1200 bytes; cap the total at 1000. Safe names, so
    // buildable with the PharData helper.
    $tar = tarMakeTarGz([
        'a.md' => str_repeat('a', 300),
        'b.md' => str_repeat('b', 300),
        'c.md' => str_repeat('c', 300),
        'd.md' => str_repeat('d', 300),
    ]);
    $dest = tarTempBasePath();

    try {
        (new TarballExtractor(maxTotalBytes: 1000))->extract($tar, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SIZE_LIMIT);
    } finally {
        @unlink($tar);
        BundleExtractor::recursivelyRemove($dest);
    }
});

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
