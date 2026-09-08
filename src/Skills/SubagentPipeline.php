<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Sync\SyncEngine;

/**
 * Host + vendor subagent ingest, end to end: load, tag-filter, resolve
 * collisions, then satisfy the `subagent:` demands of the skills that ship.
 *
 * Deliberately a collaborator rather than more methods on
 * {@see SyncEngine}. The engine is a known
 * god-object whose complexity is an accepted, capped debt; a new artefact class
 * belongs beside the resolvers it drives, not inside it.
 *
 * The order matters and mirrors skills exactly: tag-filter each vendor BEFORE
 * collision resolution, so only shippable content competes for a name.
 *
 * @internal
 */
final readonly class SubagentPipeline
{
    public function __construct(private SubagentLoader $loader) {}

    /**
     * Whether any CONFIGURED agent can receive subagents. When none can, boost
     * writes no subagent file — so an emission-time collision warning would be
     * claiming a conflict that cannot happen.
     *
     * @param  list<AgentTarget>  $agentTargets
     */
    public static function canEmit(array $agentTargets, BoostConfig $config): bool
    {
        foreach ($agentTargets as $target) {
            if ($config->hasAgent($target->agent()) && $target->subagentsDirectoryRelative() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Subagent>  $subagents  Everything the package ships, unfiltered.
     *
     * @throws DuplicateSubagentNameException
     */
    private static function assertNoDuplicateNames(string $vendor, array $subagents): void
    {
        /** @var array<string, Subagent> $seen */
        $seen = [];
        foreach ($subagents as $subagent) {
            if (isset($seen[$subagent->name])) {
                throw new DuplicateSubagentNameException(
                    name: $subagent->name,
                    vendor: $vendor,
                    paths: [$seen[$subagent->name]->sourcePath, $subagent->sourcePath],
                );
            }

            $seen[$subagent->name] = $subagent;
        }
    }

    /**
     * @param  list<DiscoveredVendor>  $allowedVendors
     * @param  list<Skill>  $shippedSkills  Post-resolution skills whose `subagent:` demands drive rescue.
     *
     * @throws CollidingSubagentsException  Two vendors publish one name and `$force` is false.
     * @throws DuplicateSubagentNameException  One package ships two files claiming one name.
     */
    public function resolve(BoostConfig $config, array $allowedVendors, bool $force, array $shippedSkills): SubagentResolution
    {
        $hostLoad = $this->loader->load($config->subagentsPath);
        $loadWarnings = $hostLoad['warnings'];

        $filter = new SubagentTagFilter();
        $vendorSubagents = [];
        /** @var array<string, array{tagMismatch: list<Subagent>, excluded: list<Subagent>}> $retainedDrops */
        $retainedDrops = [];

        foreach ($allowedVendors as $vendor) {
            if ($vendor->subagentsPath === null) {
                continue;
            }

            $load = $this->loader->load($vendor->subagentsPath, $vendor->name);
            $loadWarnings = [...$loadWarnings, ...$load['warnings']];

            // BEFORE filtering: an authoring mistake must not hide behind a tag
            // the consumer happens not to declare, or behind an exclude. The
            // package ships the same bug to everyone either way.
            self::assertNoDuplicateNames($vendor->name, $load['subagents']);

            $filtered = $filter->filter($load['subagents'], $config);
            $vendorSubagents[$vendor->name] = $filtered['kept'];
            // Retained so a `subagent:` require can rescue a tag-dropped one,
            // and so an excluded one reports as `excluded`, not `missing`.
            $retainedDrops[$vendor->name] = [
                'tagMismatch' => $filtered['tagMismatchDrops'],
                'excluded' => $filtered['excludedDrops'],
            ];
        }

        /** @var list<array{subagent: string, shadowedVendor: string}> $shadows */
        $shadows = [];
        /** @var list<string> $duplicateWarnings */
        $duplicateWarnings = [];
        $resolved = (new SubagentResolver())->resolve($hostLoad['subagents'], $vendorSubagents, $force, $shadows, $duplicateWarnings);

        $dependencies = (new SubagentDependencyResolver())->resolve($shippedSkills, $resolved, $retainedDrops, $force);

        return new SubagentResolution(
            subagents: $dependencies['subagents'],
            shadows: $shadows,
            loadWarnings: [...$loadWarnings, ...$duplicateWarnings],
            pulls: $dependencies['pulls'],
            dependencyWarnings: $dependencies['warnings'],
        );
    }
}
