<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Finder\Finder;

/**
 * Finds source files a loader will DROP because no registered renderer claims
 * their extension — the silent-capability-loss guard behind the warnings
 * surfaced by {@see GuidelineLoader}, {@see SkillLoader}, and `boost doctor`.
 *
 * Single source of truth so the sync-path loaders and the doctor health check
 * classify identically. Read-only; returns advisory warning strings.
 *
 * Scope differs by source kind:
 *  - Skills are `<name>/SKILL.md` by convention, so skill scanning is restricted
 *    to `SKILL.*` files — asset files inside a skill dir never false-trigger.
 *  - Guidelines have no filename convention, so guideline scanning warns on any
 *    file whose extension no renderer claims AND which is not a recognized binary
 *    or data ASSET (images, archives, JSON/YAML/etc.). This catches every
 *    template-source extension — single-segment (`.mdx`, `.liquid`) and
 *    multi-segment (`.blade.php`) alike — while leaving auxiliary assets a host
 *    or vendor keeps alongside its guidelines unflagged.
 */
final readonly class UnrenderableSourceScanner
{
    /**
     * Extensions that are never a renderable guideline/skill source — flagging
     * them (and advising "rename to .md") would be misleading noise, especially
     * for auxiliary files a vendor ships that the consumer can't act on.
     */
    private const ASSET_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp',
        'zip', 'tar', 'gz', 'tgz', 'bz2', '7z',
        'pdf', 'json', 'yaml', 'yml', 'xml', 'toml', 'lock', 'ini', 'csv', 'tsv',
        'txt', 'log', 'env',
    ];

    /**
     * Host + allowlisted-vendor source skips, matching what `boost sync` loads
     * (and warns on) — so `boost doctor` doesn't report a clean bill of health
     * while sync would drop a vendor-provided skill/guideline.
     *
     * @return list<string>
     */
    public function allSourceSkips(BoostConfig $config, InstalledPackages $packages): array
    {
        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        $warnings = [
            ...$this->skillSkips($config->skillsPath, $dispatcher),
            ...$this->guidelineSkips($config->guidelinesPath, $dispatcher),
        ];

        foreach ((new VendorScanner($packages))->discover() as $vendor) {
            if (! $config->isVendorAllowed($vendor->name)) {
                continue;
            }

            if ($vendor->skillsPath !== null) {
                $warnings = [...$warnings, ...$this->skillSkips($vendor->skillsPath, $dispatcher)];
            }

            if ($vendor->guidelinesPath !== null) {
                $warnings = [...$warnings, ...$this->guidelineSkips($vendor->guidelinesPath, $dispatcher)];
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    public function guidelineSkips(string $directory, SkillRendererDispatcher $dispatcher): array
    {
        return $this->scan($directory, $dispatcher, null, 'guideline', '.md');
    }

    /**
     * @return list<string>
     */
    public function skillSkips(string $directory, SkillRendererDispatcher $dispatcher): array
    {
        return $this->scan($directory, $dispatcher, 'SKILL.*', 'skill', 'SKILL.md');
    }

    /**
     * @return list<string>
     */
    private function scan(string $directory, SkillRendererDispatcher $dispatcher, ?string $nameGlob, string $label, string $renameHint): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->ignoreDotFiles(true)
            ->sortByName();

        if ($nameGlob !== null) {
            $finder->name($nameGlob);
        }

        $warnings = [];
        foreach ($finder as $file) {
            $filename = $file->getFilename();
            if ($dispatcher->resolve($filename) instanceof MatchedRenderer) {
                continue;
            }

            if ($this->isAsset($filename)) {
                continue;
            }

            $warnings[] = sprintf(
                '%s `%s` skipped — no renderer registered for its extension. Register a SkillRenderer for it via withSkillRenderers() (e.g. a BladeRenderer for `.blade.php`), or rename the file to `%s`.',
                $label,
                $file->getRelativePathname(),
                $renameHint,
            );
        }

        return $warnings;
    }

    /**
     * A file with no extension, or a recognized binary/data asset extension, is
     * never a renderable template source — so a missing renderer can't be the
     * reason it didn't ship, and flagging it would be noise.
     */
    private function isAsset(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext === '' || in_array($ext, self::ASSET_EXTENSIONS, true);
    }
}
