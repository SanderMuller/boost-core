<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Sync\OrphanReaper;
use SanderMuller\BoostCore\Sync\SyncManifest;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * A throwaway project root under the system temp dir.
 */
function reaperTempRoot(): string
{
    $root = sys_get_temp_dir() . '/boost-reaper-' . bin2hex(random_bytes(6));
    mkdir($root, 0o755, true);

    return $root;
}

/**
 * Write $content to $root/$relativePath and return a prior manifest that owns
 * that path as engine guidance with the matching sha (so it is a reapable
 * orphan when absent from the sync's intended set).
 */
function reaperManifestOwning(string $root, string $relativePath, string $content): SyncManifest
{
    file_put_contents($root . '/' . $relativePath, $content);

    return SyncManifest::empty()->withEntry(
        $relativePath,
        hash('sha256', $content),
        SyncManifest::CATEGORY_GUIDANCE,
        SyncManifest::PROVENANCE_ENGINE,
    );
}

/**
 * @param  list<WrittenFile>  $writes
 * @return list<string>
 */
function reaperPaths(array $writes): array
{
    return array_map(static fn (WrittenFile $written): string => $written->relativePath, $writes);
}

it('previews a manifest-orphan guidance reap as WOULD_DELETE and removes nothing under --check', function (): void {
    $root = reaperTempRoot();
    $manifest = reaperManifestOwning($root, 'GEMINI.md', "boost-owned guidance\n");

    try {
        // GEMINI.md is owned by the prior manifest but NOT in ownedGuidancePaths
        // this sync → an orphan. Check mode must PREVIEW the delete, not perform it.
        $result = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], [], [], checkOnly: true);

        expect($result['writes'])->toHaveCount(1)
            ->and($result['writes'][0]->action)->toBe(WriteAction::WOULD_DELETE)
            ->and($result['writes'][0]->relativePath)->toBe('GEMINI.md')
            ->and($result['retained'])
            ->toBeEmpty()
            ->and(is_file($root . '/GEMINI.md'))->toBeTrue();   // nothing removed
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('deletes the same orphan and reports DELETED in real mode (check==real parity)', function (): void {
    $root = reaperTempRoot();
    $manifest = reaperManifestOwning($root, 'GEMINI.md', "boost-owned guidance\n");

    try {
        // Preview first, then perform on an identical fresh fixture: the paths a
        // --check run reports as WOULD_DELETE must equal the paths a real run deletes.
        $check = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], [], [], checkOnly: true);
        $real = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], [], [], checkOnly: false);

        expect(reaperPaths($check['writes']))->toBe(reaperPaths($real['writes']))
            ->and($real['writes'][0]->action)->toBe(WriteAction::DELETED)
            ->and(is_file($root . '/GEMINI.md'))->toBeFalse();   // actually gone
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('never previews a reap for an operator-edited (sha-diverged) guidance file', function (): void {
    $root = reaperTempRoot();
    // Manifest records the ORIGINAL sha; disk now holds hand-edited content.
    $manifest = SyncManifest::empty()->withEntry(
        'GEMINI.md',
        hash('sha256', "original boost content\n"),
        SyncManifest::CATEGORY_GUIDANCE,
        SyncManifest::PROVENANCE_ENGINE,
    );
    file_put_contents($root . '/GEMINI.md', "operator hand-edited this\n");

    try {
        $check = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], [], [], checkOnly: true);
        $real = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], [], [], checkOnly: false);

        expect($check['writes'])
            ->toBeEmpty()
            ->and($real['writes'])
            ->toBeEmpty()
            ->and(is_file($root . '/GEMINI.md'))->toBeTrue();   // preserved (never-lossy)
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('does not reap a guidance file still intended this sync', function (): void {
    $root = reaperTempRoot();
    $manifest = reaperManifestOwning($root, 'GEMINI.md', "boost-owned guidance\n");

    try {
        // GEMINI.md IS in ownedGuidancePaths → intended → never a reap candidate.
        $result = OrphanReaper::reapManifestOrphans($root, $manifest, [], [], ['GEMINI.md'], [], checkOnly: true);

        expect($result['writes'])
            ->toBeEmpty()
            ->and(is_file($root . '/GEMINI.md'))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});
