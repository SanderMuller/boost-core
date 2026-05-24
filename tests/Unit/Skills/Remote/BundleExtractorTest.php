<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\RemoteExtractException;

function bundleTempPath(string $suffix = '.skill'): string
{
    return sys_get_temp_dir() . '/boost-bundle-test-' . bin2hex(random_bytes(6)) . $suffix;
}

/**
 * @param  array<string, string>  $entries
 */
function bundleMakeZip(array $entries, ?string $path = null): string
{
    $path ??= bundleTempPath('.skill');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

it('extracts a well-formed bundle into the destination directory', function (): void {
    $bundle = bundleMakeZip([
        'composer-upgrade/SKILL.md' => "---\nname: composer-upgrade\n---\nBody.",
        'composer-upgrade/references/r1.md' => 'reference one',
    ]);
    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor())->extract($bundle, $dest);

        expect(file_exists($dest . '/composer-upgrade/SKILL.md'))->toBeTrue()
            ->and(file_get_contents($dest . '/composer-upgrade/SKILL.md'))->toContain('Body.')
            ->and(file_exists($dest . '/composer-upgrade/references/r1.md'))->toBeTrue();
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an archive that cannot be opened (MALFORMED)', function (): void {
    $bogus = bundleTempPath('.skill');
    file_put_contents($bogus, 'NOT A ZIP');

    try {
        (new BundleExtractor())->extract($bogus, bundleTempPath('-dest'));
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::MALFORMED);
    } finally {
        @unlink($bogus);
    }
});

it('rejects an entry with `..` segments (PATH_TRAVERSAL)', function (): void {
    $bundle = bundleMakeZip([
        'foo/../../escape.md' => 'evil',
    ]);
    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor())->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::PATH_TRAVERSAL)
            ->and(is_dir($dest))
            ->toBeFalse('Destination must be untouched after rejection.');
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an entry with an absolute path (ABSOLUTE_PATH)', function (): void {
    $bundle = bundleMakeZip([
        '/etc/passwd' => 'evil',
    ]);
    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor())->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::ABSOLUTE_PATH);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an entry with a Windows drive-letter absolute path (ABSOLUTE_PATH)', function (): void {
    $bundle = bundleMakeZip([
        'C:/evil.md' => 'evil',
    ]);
    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor())->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::ABSOLUTE_PATH);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an archive containing a symbolic link entry (SYMLINK)', function (): void {
    // Build a ZIP and mark one entry as a Unix symlink via external attributes.
    $bundle = bundleTempPath('.skill');
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE);
    $zip->addFromString('symlink-entry', '/etc/passwd');
    // Symlink mode: 0xA1FF in high bits → (0xA1FF << 16). 0xA000 = symlink, 0x01FF = rwxrwxrwx.
    $zip->setExternalAttributesName('symlink-entry', ZipArchive::OPSYS_UNIX, 0xA1FF << 16);
    $zip->close();

    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor())->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SYMLINK);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

// Adversarial size/count tests use tiny caps via the extractor's constructor
// so we avoid building 200MB / 11000-entry archives on every test run.
// Production defaults (50MB / 200MB / 10000) are wired by the `RemoteSkillCache`
// at the call site; the validation LOGIC is what matters here.

it('rejects an archive with > maxEntries (ENTRY_COUNT)', function (): void {
    $bundle = bundleTempPath('.skill');
    $zip = new ZipArchive();
    $zip->open($bundle, ZipArchive::CREATE);
    for ($i = 0; $i <= 5; ++$i) {
        $zip->addFromString("file-{$i}.md", '.');
    }

    $zip->close();

    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor(maxEntries: 5))->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::ENTRY_COUNT);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an archive whose total uncompressed size exceeds maxTotalBytes (SIZE_LIMIT)', function (): void {
    $bundle = bundleMakeZip([
        'a.bin' => str_repeat('a', 200),
        'b.bin' => str_repeat('b', 200),
        'c.bin' => str_repeat('c', 200),
    ]);
    $dest = bundleTempPath('-dest');

    try {
        // total ~600 bytes, cap 400.
        (new BundleExtractor(maxFileBytes: 1000, maxTotalBytes: 400))->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SIZE_LIMIT);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});

it('rejects an archive whose single file exceeds maxFileBytes (SIZE_LIMIT)', function (): void {
    $bundle = bundleMakeZip(['huge.bin' => str_repeat('a', 1024)]);
    $dest = bundleTempPath('-dest');

    try {
        (new BundleExtractor(maxFileBytes: 500))->extract($bundle, $dest);
        throw new RuntimeException('Expected RemoteExtractException.');
    } catch (RemoteExtractException $remoteExtractException) {
        expect($remoteExtractException->reason)->toBe(RemoteExtractException::SIZE_LIMIT);
    } finally {
        @unlink($bundle);
        BundleExtractor::recursivelyRemove($dest);
    }
});
