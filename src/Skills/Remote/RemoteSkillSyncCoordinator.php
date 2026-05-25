<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillTagFilter;
use SanderMuller\BoostCore\Sync\SkillSourceCollisionException;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * Coordinates the remote-skill side of a sync — fetching + tag-filtering +
 * orphan-pruning. Extracted from {@see SyncEngine}
 * so the engine's cognitive-complexity budget stays under the per-class cap
 * as the remote-skill surface grows.
 */
final readonly class RemoteSkillSyncCoordinator
{
    public function __construct(
        private RemoteSkillIngester $ingester,
        private RemoteOrphanPruner $orphanPruner,
        private SkillTagFilter $skillTagFilter,
    ) {}

    /**
     * Fetch each declared remote source via the ingester, tag-filter the
     * result identically to Composer vendors, and merge into `$vendorSkills`.
     * Per-source errors are isolated; `BOOST_REMOTE_STRICT` (handled inside
     * the ingester) escalates the first failure to throw.
     *
     * @param  array<string, list<Skill>>  $vendorSkills  appended to in-place
     * @param  list<string>  $droppedNames  appended to in-place
     * @param  int  $tagFilteredCount  summed in-place
     * @return array{skills: array<string, list<Skill>>, errors: list<string>}
     */
    public function ingestIntoVendorMap(
        BoostConfig $config,
        array &$vendorSkills,
        array &$droppedNames,
        int &$tagFilteredCount,
        ?SkillRendererDispatcher $renderers = null,
        bool $checkOnly = false,
    ): array {
        // Check mode: filter `$config->remoteSkills` to only those already
        // cached offline. Cache misses are recorded as a "would-fetch"
        // advisory in errors[] and skipped — avoids the side effects of
        // network calls + cache writes during `boost sync --check`.
        [$sources, $skippedAdvisories] = $checkOnly
            ? $this->filterToOfflineCached($config->remoteSkills)
            : [$config->remoteSkills, []];

        $remote = $this->ingester->ingest($sources, $renderers);
        if ($skippedAdvisories !== []) {
            $remote['errors'] = array_merge($remote['errors'], $skippedAdvisories);
        }

        foreach ($remote['skills'] as $sourceName => $remoteSkills) {
            if ($remoteSkills === []) {
                continue;
            }

            $filtered = $this->skillTagFilter->filter($remoteSkills, $config);

            // Same-vendor collision detection — mirrors InjectedVendorMerger.
            // A remote source sharing a vendor key with an already-populated
            // entry (scanned vendor with the same Composer name, or an
            // injected vendor of the same key) would otherwise silently
            // first-win via array_merge. SkillResolver only catches
            // cross-vendor collisions.
            $existingNames = [];
            foreach ($vendorSkills[$sourceName] ?? [] as $existing) {
                $existingNames[$existing->name] = true;
            }

            foreach ($filtered['kept'] as $skill) {
                if (isset($existingNames[$skill->name])) {
                    throw new SkillSourceCollisionException(sprintf(
                        'remote source `%s` publishes skill `%s` that also exists in the scanned or injected vendor map under the same vendor key. Rename one or use a distinct injection/scan vendor key.',
                        $sourceName,
                        $skill->name,
                    ));
                }
            }

            $vendorSkills[$sourceName] = array_merge($vendorSkills[$sourceName] ?? [], $filtered['kept']);
            foreach ($filtered['droppedNames'] as $name) {
                $droppedNames[] = $name;
            }

            $tagFilteredCount += $filtered['droppedByTag'];
        }

        return $remote;
    }

    /**
     * Split a remote-source list into `[offline-ready, would-fetch
     * advisories]` for `--check`-mode use. Sources missing from the
     * offline cache are excluded from the ingest call (preventing the
     * network fetch + cache write) and surfaced as advisory strings.
     *
     * @param  list<RemoteSkillSource>  $sources
     * @return array{0: list<RemoteSkillSource>, 1: list<string>}
     */
    private function filterToOfflineCached(array $sources): array
    {
        $ready = [];
        $skipped = [];
        foreach ($sources as $candidate) {
            if (! $this->ingester->isSourceCached($candidate)) {
                $skipped[] = sprintf(
                    'remote source `%s@%s` would fetch on a real sync (not in offline cache).',
                    $candidate->source,
                    $candidate->version,
                );

                continue;
            }

            $ready[] = $candidate;
        }

        return [$ready, $skipped];
    }

    /**
     * Prune what was remote-managed last sync but no longer is, then persist
     * this sync's remote-skill declaration set for the next run's diff.
     *
     * Pruning runs even on a sync where every remote source failed to
     * resolve — a config change that DROPPED a source must take effect
     * regardless of network state. A still-declared source whose fetch
     * failed has its name kept in the protected set, so the previously
     * cached agent-dir copy survives the outage. Check mode skips the
     * manifest write to avoid corrupting the next real sync's view of state.
     *
     * @param  list<AgentTarget>  $agentTargets
     * @param  list<Skill>  $resolvedSkills
     * @return list<WrittenFile>
     */
    public function applyOrphanPruning(
        string $projectRoot,
        array $agentTargets,
        BoostConfig $config,
        array $resolvedSkills,
        bool $checkOnly,
    ): array {
        $declaredRemoteNames = $this->collectDeclaredRemoteNames($config);

        // Protected set = anything this sync resolved (any source) UNION
        // anything still declared in `withRemoteSkills` (intent preserved
        // even when fetch fails this sync).
        $protectedNames = [];
        foreach ($resolvedSkills as $skill) {
            $protectedNames[$skill->name] = true;
        }

        foreach ($declaredRemoteNames as $name) {
            $protectedNames[$name] = true;
        }

        $writes = $this->orphanPruner->pruneOrphans(
            $projectRoot,
            $agentTargets,
            $protectedNames,
            $checkOnly,
        );

        if (! $checkOnly) {
            $this->orphanPruner->writeManifest($projectRoot, $declaredRemoteNames);
        }

        return $writes;
    }

    /**
     * Names declared in `withRemoteSkills(...)` this sync, regardless of
     * whether each one's fetch + extract succeeded. Used both as the
     * pruner's protected set and as the next-sync manifest contents.
     *
     * @return list<string>
     */
    private function collectDeclaredRemoteNames(BoostConfig $config): array
    {
        $names = [];
        foreach ($config->remoteSkills as $source) {
            foreach ($source->skills as $ref) {
                $names[] = $ref->name;
            }
        }

        return $names;
    }
}
