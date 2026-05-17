<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use SanderMuller\BoostCore\Enums\Agent;

/**
 * Resolved, immutable boost configuration.
 *
 * Users author this indirectly via `boost.php`:
 *
 *     return BoostConfig::configure()
 *         ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
 *         ->withAllowedVendors(['doctrine/orm', 'symfony/symfony'])
 *         ->withSkillsPath(__DIR__ . '/.ai/skills')
 *         ->withGuidelinesPath(__DIR__ . '/.ai/guidelines');
 *
 * `configure()` returns a BoostConfigBuilder; the builder accumulates state.
 * BoostConfigLoader calls `->build($projectRoot)` to produce this immutable
 * value object.
 */
final readonly class BoostConfig
{
    /**
     * @param  list<Agent>  $agents
     * @param  list<string>  $allowedVendors  Composer vendor/package names
     * @param  list<string>  $disabledEmitters  Fully-qualified class names
     */
    public function __construct(
        public array $agents,
        public array $allowedVendors,
        public string $skillsPath,
        public string $guidelinesPath,
        public array $disabledEmitters,
    ) {}

    public static function configure(): BoostConfigBuilder
    {
        return new BoostConfigBuilder();
    }

    public function hasAgent(Agent $agent): bool
    {
        return in_array($agent, $this->agents, true);
    }

    public function isVendorAllowed(string $packageName): bool
    {
        return in_array($packageName, $this->allowedVendors, true);
    }

    public function isEmitterDisabled(string $fqcn): bool
    {
        return in_array($fqcn, $this->disabledEmitters, true);
    }
}
