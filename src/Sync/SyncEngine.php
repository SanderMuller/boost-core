<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Discovery\DiscoveredEmitter;
use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Discovery\EmitterDiscovery;
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
 * Pipeline:
 * 1. Load boost.php config.
 * 2. Snapshot installed Composer packages.
 * 3. Walk vendor publishers, filter by allowlist.
 * 4. Discover FileEmitter classes from allowlisted vendors.
 * 5. Load host + vendor skills/guidelines.
 * 6. Resolve collisions (host wins, vendor-vs-vendor strict unless --force).
 * 7. Run FileEmitters (before fan-out — one-way dependency).
 * 8. Agent fan-out: skills + guidelines per active agent.
 */
final class SyncEngine
{
    private readonly InstalledPackages $installedPackages;

    private readonly SkillLoader $skillLoader;

    private readonly GuidelineLoader $guidelineLoader;

    private readonly VendorScanner $vendorScanner;

    private readonly EmitterDiscovery $emitterDiscovery;

    /**
     * @param  list<AgentTarget>  $agentTargets
     */
    public function __construct(
        private readonly array $agentTargets,
        private readonly BoostConfigLoader $configLoader = new BoostConfigLoader(),
        private readonly FrontmatterParser $frontmatterParser = new FrontmatterParser(),
        private readonly SkillResolver $skillResolver = new SkillResolver(),
        private readonly GuidelineResolver $guidelineResolver = new GuidelineResolver(),
        private readonly FileWriter $writer = new FileWriter(),
        ?InstalledPackages $installedPackages = null,
    ) {
        $this->installedPackages = $installedPackages ?? InstalledPackages::fromComposer();
        $this->skillLoader = new SkillLoader($this->frontmatterParser);
        $this->guidelineLoader = new GuidelineLoader($this->frontmatterParser);
        $this->vendorScanner = new VendorScanner($this->installedPackages);
        $this->emitterDiscovery = new EmitterDiscovery($this->installedPackages);
    }

    public static function default(?InstalledPackages $installedPackages = null): self
    {
        return new self(
            agentTargets: [
                new ClaudeCodeTarget(),
                new CursorTarget(),
                new CopilotTarget(),
                new CodexTarget(),
                new GeminiTarget(),
                new JunieTarget(),
                new KiroTarget(),
                new OpenCodeTarget(),
                new AmpTarget(),
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
            return new SyncResult(writes: [], emitters: [], errors: [$e->getMessage()], check: $checkOnly);
        }

        $context = new SyncContext(
            projectRoot: $projectRoot,
            packages: $this->installedPackages,
            config: $config,
        );

        $emitterResults = $this->runEmitters($projectRoot, $config, $context, $checkOnly);

        return $this->fanOut(
            $projectRoot,
            $config,
            $resolvedSkills,
            $resolvedGuidelines,
            $emitterResults,
            $checkOnly,
        );
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
     * @return list<EmitterResult>
     */
    private function runEmitters(string $projectRoot, BoostConfig $config, SyncContext $context, bool $checkOnly): array
    {
        $allowedVendors = $config->allowedVendors;
        $discovered = $this->emitterDiscovery->discover($allowedVendors);

        /** @var list<EmitterResult> $results */
        $results = [];
        /** @var array<string, string> $claimedPaths Path → emitter FQCN, for collision detection. */
        $claimedPaths = [];

        foreach ($discovered as $emitter) {
            if ($config->isEmitterDisabled($emitter->fqcn)) {
                $results[] = new EmitterResult(
                    fqcn: $emitter->fqcn,
                    vendor: $emitter->vendor,
                    action: EmitterAction::DISABLED,
                    relativePath: null,
                    reason: 'Disabled via withDisabledEmitters() in boost.php.',
                );

                continue;
            }

            $results[] = $this->runOneEmitter($emitter, $context, $projectRoot, $checkOnly, $claimedPaths);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $claimedPaths
     */
    private function runOneEmitter(
        DiscoveredEmitter $emitter,
        SyncContext $context,
        string $projectRoot,
        bool $checkOnly,
        array &$claimedPaths,
    ): EmitterResult {
        try {
            $emitted = $emitter->emitter->emit($context);
        } catch (Throwable $e) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: null,
                reason: $e->getMessage(),
            );
        }

        if ($emitted === null) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::SKIPPED,
                relativePath: null,
                reason: 'emit() returned null.',
            );
        }

        if (isset($claimedPaths[$emitted->relativePath])) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $emitted->relativePath,
                reason: sprintf(
                    'Path "%s" already claimed by emitter %s.',
                    $emitted->relativePath,
                    $claimedPaths[$emitted->relativePath],
                ),
            );
        }
        $claimedPaths[$emitted->relativePath] = $emitter->fqcn;

        try {
            $write = $this->writer->write(
                $projectRoot,
                new PendingWrite($emitted->relativePath, $emitted->content),
                $checkOnly,
            );
        } catch (Throwable $e) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $emitted->relativePath,
                reason: $e->getMessage(),
            );
        }

        return new EmitterResult(
            fqcn: $emitter->fqcn,
            vendor: $emitter->vendor,
            action: $this->mapWriteAction($write->action),
            relativePath: $write->relativePath,
            reason: null,
        );
    }

    private function mapWriteAction(WriteAction $action): EmitterAction
    {
        return match ($action) {
            WriteAction::WROTE => EmitterAction::WROTE,
            WriteAction::UNCHANGED => EmitterAction::UNCHANGED,
            WriteAction::WOULD_WRITE => EmitterAction::WOULD_WRITE,
        };
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @param  list<EmitterResult>  $emitterResults
     */
    private function fanOut(
        string $projectRoot,
        BoostConfig $config,
        array $skills,
        array $guidelines,
        array $emitterResults,
        bool $checkOnly,
    ): SyncResult {
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

        return new SyncResult(writes: $writes, emitters: $emitterResults, errors: $errors, check: $checkOnly);
    }
}
