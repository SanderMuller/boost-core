<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Sync\InstalledPackages;

/**
 * Walks the installed Composer packages restricted to a given allowlist
 * and aggregates every distinct `metadata.boost-tags` value declared by
 * their skills + guidelines.
 *
 * Powers the `boost install` tag picker: the operator sees only tags
 * actually published by the vendors they just selected, with a count
 * of items each tag would unlock — far less guessing than reading the
 * Tag enum and hoping.
 */
final readonly class AvailableTagsDiscovery
{
    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * Return tag → number of skills+guidelines that would unlock if
     * the consumer adds the tag to `withTags()`. Tags are normalized
     * lowercase; the count combines skills + guidelines that declare
     * the tag (any item declaring the tag — items with multiple tags
     * are counted once per tag they declare).
     *
     * `$renderers` defaults to passthrough-only (`.md` discovery only).
     * A caller that's already loaded `BoostConfig` should pass
     * `$config->skillRenderers` so renderer-backed assets (Blade etc.)
     * are walked the same way `boost sync` would walk them — otherwise
     * tags declared in `.blade.php` skills stay invisible to the picker
     * even though they'd ship live.
     *
     * @param  list<string>  $allowedVendors  Composer package names
     * @param  list<SkillRenderer>  $renderers  caller-known renderers (host's `withSkillRenderers([...])`)
     * @return array<string, int>             tag → unlock count
     */
    public function discover(array $allowedVendors, array $renderers = []): array
    {
        if ($allowedVendors === []) {
            return [];
        }

        $allowed = array_flip($allowedVendors);
        $skillLoader = new SkillLoader(new FrontmatterParser());
        $guidelineLoader = new GuidelineLoader(new FrontmatterParser());
        $scanner = new VendorScanner($this->packages);

        // Always append the implicit Passthrough so plain `.md` files
        // discover even when the caller forgets to include it.
        $dispatcher = new SkillRendererDispatcher([...$renderers, new PassthroughRenderer()]);

        $counts = [];
        foreach ($scanner->discover() as $vendor) {
            if (! isset($allowed[$vendor->name])) {
                continue;
            }

            if ($vendor->skillsPath !== null) {
                foreach ($skillLoader->load($vendor->skillsPath, $vendor->name, $dispatcher) as $skill) {
                    foreach ($skill->tags as $tag) {
                        $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                    }
                }
            }

            if ($vendor->guidelinesPath !== null) {
                foreach ($guidelineLoader->load($vendor->guidelinesPath, $vendor->name, $dispatcher) as $guideline) {
                    foreach ($guideline->tags as $tag) {
                        $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                    }
                }
            }
        }

        ksort($counts);

        return $counts;
    }
}
