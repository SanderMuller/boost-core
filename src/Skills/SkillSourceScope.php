<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Finder\SplFileInfo;

/**
 * The single skill-source layout rule, shared by {@see SkillLoader} (what
 * ships) and {@see UnrenderableSourceScanner} (what `boost doctor` reports), so
 * the two classify identically.
 *
 * Two layouts coexist in the wild:
 *  - FLAT: `skills/foo.md` → skill `foo` (any top-level renderable file).
 *  - NESTED: `skills/foo/SKILL.md` (+ `references/`, `examples/`) → skill `foo`.
 *
 * So a skill source is a TOP-LEVEL file, OR a `SKILL.*` file exactly one
 * directory deep. Anything deeper — `foo/references/app.md`, `foo/examples/…`
 * — or a non-`SKILL.*` file inside a skill dir is an asset, never a standalone
 * skill. Without this rule a depth-unbounded scan shipped nested reference
 * files as phantom top-level skills.
 *
 * @internal
 */
final class SkillSourceScope
{
    /**
     * Filename prefix marking a nested skill's entry file (`SKILL.md`,
     * `SKILL.blade.php`, …). Uppercase by convention, matched case-sensitively
     * to mirror the prior `SKILL.*` Finder glob.
     */
    private const ENTRY_PREFIX = 'SKILL.';

    public static function isSkillSource(SplFileInfo $file): bool
    {
        // Backup / editor-temp files are never skills — a `SKILL.md.bak` parked
        // beside the real `SKILL.md` (it starts with `SKILL.`), or a top-level
        // `foo.md~`, would otherwise be discovered and then warned about as having
        // no renderer for its `.bak`/`~` extension. Exclude them up front.
        if (self::isBackupOrTempFile($file->getFilename())) {
            return false;
        }

        // Finder's getRelativePath() is the DIRECTORY portion relative to the
        // scanned root: '' for a top-level file, 'foo' for foo/SKILL.md,
        // 'foo/references' for foo/references/app.md.
        $relativeDir = $file->getRelativePath();

        if ($relativeDir === '') {
            return true; // top-level flat skill
        }

        // Exactly one directory deep AND the conventional entry filename.
        return ! str_contains($relativeDir, '/')
            && ! str_contains($relativeDir, '\\')
            && self::isNestedEntryFilename($file->getFilename());
    }

    /**
     * Whether `$filename` is a nested skill's entry-file candidate
     * (`SKILL.md`, `SKILL.blade.php`, …). Shared with
     * {@see SkillAssetCollector} so "entry candidate" and "asset" partition
     * a skill directory identically.
     */
    public static function isNestedEntryFilename(string $filename): bool
    {
        return str_starts_with($filename, self::ENTRY_PREFIX);
    }

    /**
     * A backup / editor-temp filename: a trailing `~`, or a final extension of
     * `.bak` / `.orig` / `.tmp` / `.swp` / `.swo` (case-insensitive). Catches
     * `SKILL.md.bak`, `foo.md.orig`, `foo.md~` — never an intended skill source.
     */
    public static function isBackupOrTempFile(string $filename): bool
    {
        return str_ends_with($filename, '~')
            || preg_match('/\.(?:bak|orig|tmp|swp|swo)$/i', $filename) === 1;
    }
}
