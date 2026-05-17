<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\CollidingSkillsException;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\GuidelineResolver;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillResolver;
use Throwable;

/**
 * Orchestrates a full boost:sync run.
 *
 * Steps:
 * 1. Load `boost.php` config.
 * 2. Snapshot installed Composer packages.
 * 3. Walk vendor publishers, filter by allowlist.
 * 4. Load host + allowlisted-vendor skills + guidelines.
 * 5. Resolve collisions (host wins, vendor-vs-vendor strict unless --force).
 * 6. For each agent in the config, plan + write.
 *
 * FileEmitter wiring lands in a subsequent commit. Until then `emit` is a no-op.
 */
final class SyncEngine
{
    /**
     * @param  list<AgentTarget>  $agentTargets
     */
    public function __construct(
        private readonly array $agentTargets,
        private readonly BoostConfigLoader $configLoader = new BoostConfigLoader,
        private readonly FrontmatterParser $frontmatterParser = new FrontmatterParser,
        private readonly SkillResolver $skillResolver = new SkillResolver,
        private readonly GuidelineResolver $guidelineResolver = new GuidelineResolver,
        private readonly FileWriter $writer = new FileWriter,
        ?InstalledPackages $installedPackages = null,
    ) {
        $this->installedPackages = $installedPackages ?? InstalledPackages::fromComposer();
        $this->skillLoader = new SkillLoader($this->frontmatterParser);
        $this->guidelineLoader = new GuidelineLoader($this->frontmatterParser);
        $this->vendorScanner = new VendorScanner($this->installedPackages);
    }

    private readonly InstalledPackages $installedPackages;

    private readonly SkillLoader $skillLoader;

    private readonly GuidelineLoader $guidelineLoader;

    private readonly VendorScanner $vendorScanner;

    /**
     * Default wiring with all available AgentTargets. Add more targets here as
     * they ship (one per agent in `Agent` enum).
     */
    public static function default(?InstalledPackages $installedPackages = null): self
    {
        return new self(
            agentTargets: [
                new ClaudeCodeTarget,
                // Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp land in a later commit.
            ],
            installedPackages: $installedPackages,
        );
    }

    public function sync(string $projectRoot, bool $checkOnly = false, bool $force = false): SyncResult
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);

        $allowedVendors = $this->discoverAllowedVendors($config);

        try {
            $resolvedSkills = $this->resolveSkills($config, $allowedVendors, $force);
            $resolvedGuidelines = $this->resolveGuidelines($config, $allowedVendors, $force);
        } catch (CollidingSkillsException $e) {
            return new SyncResult(writes: [], errors: [$e->getMessage()], check: $checkOnly);
        }

        return $this->fanOut($projectRoot, $config, $resolvedSkills, $resolvedGuidelines, $checkOnly);
    }

    /**
     * @return list<DiscoveredVendor>
     */
    private function discoverAllowedVendors(BoostConfig $config): array
    {
        $allowed = [];
        foreach ($this->vendorScanner->discover() as $vendor) {
            if ($config->isVendorAllowed($vendor->name)) {
                $allowed[] = $vendor;
            }
        }

        return $allowed;
    }

    /**
     * @param  list<DiscoveredVendor>  $allowedVendors
     * @return list<Skill>
     */
    private function resolveSkills(BoostConfig $config, array $allowedVendors, bool $force): array
    {
        $hostSkills = is_dir($config->skillsPath)
            ? $this->skillLoader->load($config->skillsPath, null)
            : [];

        /** @var array<string, iterable<Skill>> $vendorSkills */
        $vendorSkills = [];
        foreach ($allowedVendors as $vendor) {
            if ($vendor->skillsPath !== null) {
                $vendorSkills[$vendor->name] = $this->skillLoader->load($vendor->skillsPath, $vendor->name);
            }
        }

        return $this->skillResolver->resolve($hostSkills, $vendorSkills, $force);
    }

    /**
     * @param  list<DiscoveredVendor>  $allowedVendors
     * @return list<Guideline>
     */
    private function resolveGuidelines(BoostConfig $config, array $allowedVendors, bool $force): array
    {
        $hostGuidelines = is_dir($config->guidelinesPath)
            ? $this->guidelineLoader->load($config->guidelinesPath, null)
            : [];

        /** @var array<string, iterable<Guideline>> $vendorGuidelines */
        $vendorGuidelines = [];
        foreach ($allowedVendors as $vendor) {
            if ($vendor->guidelinesPath !== null) {
                $vendorGuidelines[$vendor->name] = $this->guidelineLoader->load($vendor->guidelinesPath, $vendor->name);
            }
        }

        return $this->guidelineResolver->resolve($hostGuidelines, $vendorGuidelines, $force);
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function fanOut(string $projectRoot, BoostConfig $config, array $skills, array $guidelines, bool $checkOnly): SyncResult
    {
        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<string> $errors */
        $errors = [];

        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            foreach ($target->plan($skills, $guidelines) as $pending) {
                try {
                    $writes[] = $this->writer->write($projectRoot, $pending, $checkOnly);
                } catch (Throwable $e) {
                    $errors[] = sprintf(
                        'Failed to write %s for %s: %s',
                        $pending->relativePath,
                        $target->agent()->value,
                        $e->getMessage(),
                    );
                }
            }
        }

        return new SyncResult(writes: $writes, errors: $errors, check: $checkOnly);
    }
}
