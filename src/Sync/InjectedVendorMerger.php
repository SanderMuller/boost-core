<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillTagFilter;

/**
 * Tag-filter + collision-detect + append caller-injected skills/guidelines
 * into the vendor maps that {@see SyncEngine}'s resolveSkills/resolveGuidelines
 * builds.
 *
 * Lives outside SyncEngine to keep its class-level cognitive-complexity
 * under cap as the injection surface grows. Pure logic, no I/O.
 *
 * `SkillResolver` raises `CollidingSkillsException` only on *cross-vendor*
 * name collisions, so this merger detects same-vendor dupes (both the
 * inject-vs-scan case and the inject-list-internal case) explicitly. Throws
 * `SkillSourceCollisionException` with a message pointing at the offending key —
 * forces the caller to decide (dedupe first, or use a distinct vendor key).
 *
 * @internal
 */
final readonly class InjectedVendorMerger
{
    public function __construct(
        private SkillTagFilter $skillTagFilter,
        private GuidelineTagFilter $guidelineTagFilter,
    ) {}

    /**
     * Merge caller-supplied renderers into `BoostConfig::skillRenderers`
     * for one sync transaction. Extras are inserted JUST BEFORE the
     * trailing implicit `PassthroughRenderer` (which `BoostConfigBuilder`
     * always appends last). Resulting order: `[...user-registered,
     * ...extras, Passthrough]`. With first-match-wins dispatch:
     *  - User-registered renderers win over extras (user's `boost.php`
     *    is authoritative).
     *  - Extras override only the implicit passthrough — the documented
     *    use case for `extraSkillRenderers`.
     *
     * The merged list is NOT re-validated for extension conflicts here —
     * `BoostConfigBuilder::build` already validated the project's own
     * renderers; a duplicate from the extras list is a caller bug that
     * surfaces via dispatcher resolution (first-match-wins) rather than
     * throwing.
     *
     * @param  list<SkillRenderer>  $extras
     */
    public function mergeExtraRenderers(BoostConfig $config, array $extras): BoostConfig
    {
        if ($extras === []) {
            return $config;
        }

        // Split off the trailing implicit Passthrough; insert extras
        // between user renderers and the passthrough.
        $userRenderers = $config->skillRenderers;
        /** @var list<SkillRenderer> $trailing */
        $trailing = [];
        $lastIndex = count($userRenderers) - 1;
        if ($lastIndex >= 0 && $userRenderers[$lastIndex] instanceof PassthroughRenderer) {
            $trailing = [$userRenderers[$lastIndex]];
            array_pop($userRenderers);
        }

        return new BoostConfig(
            agents: $config->agents,
            allowedVendors: $config->allowedVendors,
            skillsPath: $config->skillsPath,
            guidelinesPath: $config->guidelinesPath,
            commandsPath: $config->commandsPath,
            disabledEmitters: $config->disabledEmitters,
            manageGitignore: $config->manageGitignore,
            tags: $config->tags,
            excludedSkills: $config->excludedSkills,
            excludedGuidelines: $config->excludedGuidelines,
            remoteSkills: $config->remoteSkills,
            skillRenderers: array_merge($userRenderers, $extras, $trailing),
            conventions: $config->conventions,
        );
    }

    /**
     * @param  array<string, list<Skill>>  $injected
     * @param  array<string, list<Skill>>  $vendorSkills  in-place
     * @param  list<string>  $droppedNames  in-place
     */
    public function mergeSkills(array $injected, array &$vendorSkills, array &$droppedNames, int &$tagFilteredCount, BoostConfig $config): void
    {
        foreach ($injected as $vendorName => $skills) {
            // Collision detection runs POST-tag-filter — only skills that
            // would actually ship participate in collision resolution.
            // Matches scanned-vendor + remote-ingest semantics (both
            // filter-then-resolve). Two injected skills named `foo` where
            // one is dropped by `withTags()` is a legal pattern.
            $filtered = $this->skillTagFilter->filter($skills, $config);

            $this->assertNoSameVendorDupes(
                $vendorName,
                $filtered['kept'],
                $vendorSkills[$vendorName] ?? [],
                'injectedVendorSkills',
                'skill',
            );

            $vendorSkills[$vendorName] = array_merge($vendorSkills[$vendorName] ?? [], $filtered['kept']);
            foreach ($filtered['droppedNames'] as $name) {
                $droppedNames[] = $name;
            }

            $tagFilteredCount += $filtered['droppedByTag'];
        }
    }

    /**
     * @param  array<string, list<Guideline>>  $injected
     * @param  array<string, list<Guideline>>  $vendorGuidelines  in-place. Always populated by GuidelineTagFilter::filter (which returns list<Guideline>) — the iterable shape in SyncEngine's local typing is historical and never materializes here.
     */
    public function mergeGuidelines(array $injected, array &$vendorGuidelines, BoostConfig $config): void
    {
        foreach ($injected as $vendorName => $guidelines) {
            // POST-filter collision check (matches mergeSkills semantics).
            $filtered = $this->guidelineTagFilter->filter($guidelines, $config);
            $existing = $vendorGuidelines[$vendorName] ?? [];

            $this->assertNoSameVendorDupes(
                $vendorName,
                $filtered,
                $existing,
                'injectedVendorGuidelines',
                'guideline',
            );

            $vendorGuidelines[$vendorName] = array_merge($existing, $filtered);
        }
    }

    /**
     * @param  iterable<Skill|Guideline>  $batch
     * @param  iterable<Skill|Guideline>  $existing
     */
    private function assertNoSameVendorDupes(string $vendorName, iterable $batch, iterable $existing, string $kind, string $itemNoun): void
    {
        $existingNames = [];
        foreach ($existing as $item) {
            $existingNames[$item->name] = true;
        }

        $seenInBatch = [];
        foreach ($batch as $item) {
            if (isset($seenInBatch[$item->name])) {
                throw new SkillSourceCollisionException(sprintf(
                    '%s[%s]: %s `%s` listed more than once. Dedupe before passing to SyncEngine::sync().',
                    $kind,
                    $vendorName,
                    $itemNoun,
                    $item->name,
                ));
            }

            if (isset($existingNames[$item->name])) {
                throw new SkillSourceCollisionException(sprintf(
                    '%s[%s]: %s `%s` also published by a scanned vendor of the same name. Use a distinct injection key or remove the scan-side copy.',
                    $kind,
                    $vendorName,
                    $itemNoun,
                    $item->name,
                ));
            }

            $seenInBatch[$item->name] = true;
        }
    }
}
