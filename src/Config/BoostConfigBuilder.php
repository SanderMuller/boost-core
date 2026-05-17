<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use SanderMuller\BoostCore\Enums\Agent;

/**
 * Fluent builder used in `boost.php`. Mutable — call chain accumulates state.
 *
 * BoostConfigLoader receives the builder from `require boost.php` and calls
 * `build($projectRoot)` to resolve defaults and produce an immutable BoostConfig.
 */
final class BoostConfigBuilder
{
    /** @var list<Agent> */
    private array $agents = [];

    /** @var list<string> */
    private array $allowedVendors = [];

    private ?string $skillsPath = null;

    private ?string $guidelinesPath = null;

    /** @var list<string> */
    private array $disabledEmitters = [];

    /**
     * @param  list<Agent>  $agents
     */
    public function withAgents(array $agents): self
    {
        $this->agents = array_values($agents);

        return $this;
    }

    /**
     * @param  list<string>  $vendors  Composer vendor/package names (e.g. "doctrine/orm")
     */
    public function withAllowedVendors(array $vendors): self
    {
        $this->allowedVendors = array_values($vendors);

        return $this;
    }

    public function withSkillsPath(string $path): self
    {
        $this->skillsPath = $path;

        return $this;
    }

    public function withGuidelinesPath(string $path): self
    {
        $this->guidelinesPath = $path;

        return $this;
    }

    /**
     * @param  list<string>  $fqcns  Fully-qualified emitter class names to skip during sync
     */
    public function withDisabledEmitters(array $fqcns): self
    {
        $this->disabledEmitters = array_values($fqcns);

        return $this;
    }

    public function build(string $projectRoot): BoostConfig
    {
        $projectRoot = rtrim($projectRoot, '/');

        return new BoostConfig(
            agents: $this->agents,
            allowedVendors: $this->allowedVendors,
            skillsPath: $this->skillsPath ?? $projectRoot . '/.ai/skills',
            guidelinesPath: $this->guidelinesPath ?? $projectRoot . '/.ai/guidelines',
            disabledEmitters: $this->disabledEmitters,
        );
    }
}
