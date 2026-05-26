<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

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
     * @param  list<string>  $allowedVendors  Composer package names
     * @return array<string, int>             tag → unlock count
     */
    public function discover(array $allowedVendors): array
    {
        if ($allowedVendors === []) {
            return [];
        }

        $allowed = array_flip($allowedVendors);
        $skillLoader = new SkillLoader(new FrontmatterParser());
        $guidelineLoader = new GuidelineLoader(new FrontmatterParser());
        $scanner = new VendorScanner($this->packages);
        // Dispatcher with the implicit Passthrough so default `.md`
        // skills/guidelines are discovered. The install picker can't
        // know which custom renderers the consumer will register in
        // their boost.php — those don't exist until install completes.
        $dispatcher = new SkillRendererDispatcher([new PassthroughRenderer()]);

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
