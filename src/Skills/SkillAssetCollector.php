<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Skills\Remote\RemoteSkillIngester;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Collects a nested skill's asset siblings — every file under the skill
 * directory that is not itself a `SKILL.*` entry candidate.
 *
 * Shared by {@see SkillLoader} (local `.ai/skills/<name>/` and vendor dirs)
 * and {@see RemoteSkillIngester}
 * (remote cache slots), so both paths classify assets identically:
 *
 *  - Only NESTED-layout skills have assets — a flat `skills/foo.md` has no
 *    directory to hold siblings, so the collector returns `[]` for it.
 *  - Top-level `SKILL.*` files in the skill dir are entry candidates
 *    (per {@see SkillSourceScope}), never assets — a `SKILL.md.license`
 *    parked beside the entry would otherwise be shipped as an asset named
 *    like an entry file.
 *  - Hidden (`.`-prefixed) files/dirs and backup/editor-temp files are
 *    skipped, mirroring the loader's own source filtering.
 *
 * Contents are read eagerly (byte-safe strings) so the resulting
 * {@see SkillAsset}s are self-contained by the time emit planning runs.
 *
 * @internal
 */
final class SkillAssetCollector
{
    /**
     * @param  string  $entryFilePath  Absolute path of the skill's `SKILL.*` entry file.
     * @return list<SkillAsset>
     */
    public static function collect(string $entryFilePath): array
    {
        // Flat-layout skills (`skills/foo.md`) have no asset siblings — their
        // parent is the scanned skills root, and treating its other files as
        // assets would ship every unrelated flat skill as this one's asset.
        if (! SkillSourceScope::isNestedEntryFilename(basename($entryFilePath))) {
            return [];
        }

        $skillDir = dirname($entryFilePath);
        if (! is_dir($skillDir)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($skillDir)
            ->ignoreDotFiles(true)
            ->filter(static fn (SplFileInfo $file): bool => self::isAsset($file))
            ->sortByName();

        $assets = [];
        foreach ($finder as $file) {
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                // Vanished between enumeration and read (race) or unreadable —
                // skip rather than abort the whole skill.
                continue;
            }

            $assets[] = new SkillAsset(
                relativePath: str_replace('\\', '/', $file->getRelativePathname()),
                contents: $contents,
            );
        }

        return $assets;
    }

    private static function isAsset(SplFileInfo $file): bool
    {
        if (SkillSourceScope::isBackupOrTempFile($file->getFilename())) {
            return false;
        }

        // A top-level `SKILL.*` file is an entry candidate, not an asset.
        // Deeper `SKILL.*` files (e.g. `examples/SKILL.md`) ARE assets — they
        // sit below the depth SkillSourceScope classifies as sources.
        return ! ($file->getRelativePath() === ''
            && SkillSourceScope::isNestedEntryFilename($file->getFilename()));
    }
}
