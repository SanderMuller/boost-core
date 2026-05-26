<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Discovery\AvailableTagsDiscovery;
use SanderMuller\BoostCore\Discovery\FirstPartyPrefixes;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function Laravel\Prompts\multiselect;

/**
 * Interactive picker for agents + vendor allowlist. Persists choices via
 * BoostConfigWriter (AST modification of boost.php).
 *
 * If boost.php doesn't exist yet, generates a starter file first, then
 * proceeds straight into the interactive picker — replaces the
 * pre-0.3 `boost:init` + `boost:install` two-step.
 *
 * First-party packages (matching FirstPartyPrefixes) are pre-checked.
 */
final class InstallCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly BoostConfigLoader $loader = new BoostConfigLoader(),
        private readonly BoostConfigWriter $writer = new BoostConfigWriter(),
        private readonly FirstPartyPrefixes $firstParty = new FirstPartyPrefixes(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:install')
            ->setDescription('Generate boost.php (if missing) and interactively pick agents + vendor allowlist.');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);
        $configPath = $projectRoot . '/boost.php';

        if (! is_file($configPath)) {
            if (file_put_contents($configPath, $this->starterContents()) === false) {
                $io->error(sprintf('Failed to write boost.php at %s.', $configPath));

                return self::FAILURE;
            }

            $io->success(sprintf('Generated starter %s', $configPath));
        }

        try {
            $config = $this->loader->load($projectRoot);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        $packages = InstalledPackages::fromComposer();
        $availableVendors = $this->discoverPublishers($packages);

        $agents = $this->pickAgents($config);
        $vendors = $this->pickVendors($config, $availableVendors);
        $tags = $this->pickTags($config, $vendors, $packages);

        try {
            $this->writer->update(
                $configPath,
                $agents,
                $vendors,
                $config->disabledEmitters,
                $tags,
            );
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Updated %s', $configPath));
        $io->writeln('Next: run <info>vendor/bin/boost sync</info> to regenerate agent files.');

        return self::SUCCESS;
    }

    /**
     * @return list<Agent>
     */
    private function pickAgents(BoostConfig $config): array
    {
        $options = [];
        $defaults = [];
        foreach (Agent::cases() as $agent) {
            $options[$agent->value] = $agent->value;
            if ($config->hasAgent($agent)) {
                $defaults[] = $agent->value;
            }
        }

        /** @var list<string> $picked */
        $picked = multiselect(
            label: 'Which AI agents should boost-core publish to?',
            options: $options,
            default: $defaults,
            hint: 'Space to toggle, enter to confirm.',
        );

        return array_map(Agent::from(...), $picked);
    }

    /**
     * @param  list<string>  $availableVendors
     * @return list<string>
     */
    private function pickVendors(BoostConfig $config, array $availableVendors): array
    {
        if ($availableVendors === []) {
            return $config->allowedVendors;
        }

        $options = [];
        $defaults = [];
        foreach ($availableVendors as $vendor) {
            $options[$vendor] = $vendor;
            $alreadyAllowed = $config->isVendorAllowed($vendor);
            $firstParty = $this->firstParty->matches($vendor);
            if ($alreadyAllowed || $firstParty) {
                $defaults[] = $vendor;
            }
        }

        /** @var list<string> $picked */
        $picked = multiselect(
            label: 'Which installed vendor packages should publish skills/guidelines?',
            options: $options,
            default: $defaults,
            hint: 'First-party packages pre-checked. Uncheck any you want to exclude.',
        );

        return $picked;
    }

    /**
     * Multi-select tag picker. Discovers the union of tags declared by
     * the just-selected vendors' skills + guidelines, pre-checks any
     * tags already in `$config->tags`, and returns the operator's
     * choice. Tag enum cases (`Tag::Php`) and the Tag enum's lowercase
     * string values both compare against the discovered tags equally —
     * the writer renders each entry as `Tag::CaseName` when possible.
     *
     * Returns `null` when there's nothing to pick from (no vendors
     * publish tagged content), which tells the writer to leave the
     * existing `withTags(...)` call untouched.
     *
     * @param  list<string>  $vendors  vendor names from the vendor picker
     * @return list<string>|null
     */
    private function pickTags(BoostConfig $config, array $vendors, InstalledPackages $packages): ?array
    {
        $available = (new AvailableTagsDiscovery($packages))->discover($vendors, $config->skillRenderers);
        if ($available === []) {
            return null;
        }

        $declared = $config->tags;
        $availableKeys = array_keys($available);

        // Preserve tags the user declared in boost.php that no installed
        // vendor publishes — common for hand-curated tags ahead of a
        // vendor adding them, or org-internal tags only the host uses.
        // The picker controls VISIBLE tags only; non-discovered declared
        // tags get merged back into the final selection silently so a
        // re-run of `boost install` doesn't strip them.
        $preserved = array_values(array_diff($declared, $availableKeys));

        $options = [];
        foreach ($available as $tag => $unlockCount) {
            $options[$tag] = sprintf('%s  (unlocks %d skill/guideline)', $tag, $unlockCount);
        }

        $defaults = array_values(array_intersect($declared, $availableKeys));

        /** @var list<string> $picked */
        $picked = multiselect(
            label: 'Which tags should boost-core enable? (vendor skills/guidelines ship only when their tags are a subset of these)',
            options: $options,
            default: $defaults,
            hint: 'Each tagged vendor skill ships only when every tag it declares is checked here. Untagged skills always ship.',
        );

        return self::mergePickedWithPreserved($picked, $preserved);
    }

    /**
     * Merge the picker's selection with declared-but-not-discovered
     * tags so a re-install never strips hand-curated entries no vendor
     * publishes. Static + side-effect-free so a focused unit test
     * locks the rule without needing an interactive multiselect.
     *
     * @param  list<string>  $picked      visible tags the operator checked
     * @param  list<string>  $preserved   declared tags absent from discovery
     * @return list<string>               de-duplicated union, picker order first
     */
    public static function mergePickedWithPreserved(array $picked, array $preserved): array
    {
        return array_values(array_unique([...$picked, ...$preserved]));
    }

    /**
     * @return list<string>
     */
    private function discoverPublishers(InstalledPackages $packages): array
    {
        $scanner = new VendorScanner($packages);
        $vendors = [];
        foreach ($scanner->discover() as $vendor) {
            $vendors[] = $vendor->name;
        }

        return $vendors;
    }

    private function starterContents(): string
    {
        return <<<'PHP'
            <?php declare(strict_types=1);

            use SanderMuller\BoostCore\Config\BoostConfig;
            use SanderMuller\BoostCore\Enums\Agent;
            use SanderMuller\BoostCore\Enums\Tag;

            /**
             * boost-core configuration.
             *
             * Generated by `vendor/bin/boost install`. Re-run that command to update
             * agents/vendors interactively, or hand-edit this file. After changes
             * run `vendor/bin/boost sync`.
             *
             * Docs: https://github.com/sandermuller/boost-core
             */
            return BoostConfig::configure()
                // Which AI agents to publish skills/guidelines to. Add Agent enum cases.
                // Example: Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT
                ->withAgents([])

                // Vendor packages allowed to publish skills/guidelines into your project.
                // Each entry is a Composer package name. Add via `vendor/bin/boost scan` or hand-edit.
                ->withAllowedVendors([])

                // Optionally disable specific FileEmitter implementations by FQCN.
                ->withDisabledEmitters([])

                // Skill tags: a vendor skill ships only when every tag in its
                // `metadata.boost-tags` is declared here. Unset = receive every
                // (untagged) skill. Accepts Tag enum cases or raw strings.
                // ->withTags(Tag::Php, Tag::Laravel)

                // Exclude specific vendor skills regardless of tags.
                // Each entry is a `vendor/package:skill-name` string.
                // ->withExcludedSkills(['acme/some-pack:unwanted-skill'])

                // Source paths (relative or absolute). Defaults shown — uncomment to override.
                // ->withSkillsPath(__DIR__ . '/.ai/skills')
                // ->withGuidelinesPath(__DIR__ . '/.ai/guidelines')
            ;

            PHP;
    }
}
