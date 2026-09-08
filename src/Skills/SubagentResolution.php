<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * What {@see SubagentPipeline} resolved, and everything the caller has to
 * report about it.
 *
 * @internal
 */
final readonly class SubagentResolution
{
    /**
     * @param  list<Subagent>  $subagents  What ships, rescues included.
     * @param  list<array{subagent: string, shadowedVendor: string}>  $shadows  Host-over-vendor overrides.
     * @param  list<string>  $loadWarnings  Per-file problems: a source declaring no `name`, or a host duplicate that lost.
     * @param  list<array{name: string, requiredBy: string, vendor: string}>  $pulls  Tag-filtered subagents a require rescued.
     * @param  list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>  $dependencyWarnings  Demands nothing satisfies.
     */
    public function __construct(
        public array $subagents,
        public array $shadows,
        public array $loadWarnings,
        public array $pulls,
        public array $dependencyWarnings,
    ) {}
}
