<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Config\ConfigScaffolder;
use SanderMuller\BoostCore\Discovery\AvailableTagsDiscovery;
use SanderMuller\BoostCore\Discovery\FirstPartyPrefixes;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function Laravel\Prompts\multiselect;

/**
 * Interactive picker for agents + vendor allowlist. Persists choices via
 * BoostConfigWriter (AST modification of boost.php).
 *
 * If boost.php doesn't exist yet, generates a starter file first, then
 * proceeds straight into the interactive picker.
 *
 * First-party packages (matching FirstPartyPrefixes) are pre-checked.
 *
 * @internal
 */
final class InstallCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly BoostConfigLoader $loader = new BoostConfigLoader(),
        private readonly BoostConfigWriter $writer = new BoostConfigWriter(),
        private readonly FirstPartyPrefixes $firstParty = new FirstPartyPrefixes(),
        private readonly ?InstalledPackages $injectedPackages = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:install')
            ->setDescription('Generate boost.php (if missing) and interactively pick agents + vendor allowlist.')
            ->addOption('config-dir', null, InputOption::VALUE_NONE, 'Scaffold a new config at .config/boost.php instead of the repo root (no effect when a config already exists).');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        try {
            $resolved = BoostConfigPath::resolve($projectRoot);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        // Edit an existing config wherever it lives; otherwise scaffold at the
        // chosen location (root by default, .config/ with --config-dir). Never
        // create a second config when one already exists.
        $configPath = self::scaffoldTarget($resolved, $input->getOption('config-dir') === true, $projectRoot);

        if (! $resolved->exists) {
            try {
                ConfigScaffolder::scaffold($configPath);
            } catch (Throwable $throwable) {
                $io->error($throwable->getMessage());

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

        // The pickers below need a TTY. Fail fast (after any scaffold above) with
        // guidance rather than hanging on a prompt under CI / --no-interaction.
        if (! $this->isInteractiveOrExplain($input, $io, "`boost install`'s agent/vendor/tag pickers need an interactive terminal. Pin them in boost.php (->withAgents([...])->withAllowedVendors([...])) and run `boost sync`, or run install without --no-interaction.")) {
            return self::FAILURE;
        }

        $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
        $availableVendors = $this->discoverPublishers($packages);

        $agents = $this->pickAgents($io, $config, $projectRoot, $packages, scaffolded: ! $resolved->exists || $this->isPristineStarter($configPath));
        $vendors = $this->pickVendors($io, $config, $availableVendors);
        $tags = $this->pickTags($io, $config, $vendors, $packages);

        $this->noteLaravelBoostCoexistence($io, $packages);

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
    private function pickAgents(SymfonyStyle $io, BoostConfig $config, string $projectRoot, InstalledPackages $packages, bool $scaffolded): array
    {
        $adopted = $scaffolded ? $this->adoptableAgents($io, $projectRoot, $packages) : [];

        $options = [];
        $defaults = [];
        foreach (Agent::cases() as $agent) {
            $options[$agent->value] = $agent->value;
            if ($config->hasAgent($agent) || isset($adopted[$agent->value])) {
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
     * Is this config still the untouched starter this command writes?
     *
     * Scaffolding happens before the TTY check, so a first `boost install` in a
     * non-interactive shell (or one that is cancelled) leaves a starter `boost.php`
     * behind. Without this, the interactive retry would read that leftover as an
     * existing config the operator had chosen to leave agent-less, and skip adoption
     * for good. Byte equality with the template is the safe test: any edit at all
     * makes it the operator's file again.
     */
    private function isPristineStarter(string $configPath): bool
    {
        return @file_get_contents($configPath) === $this->starterContents();
    }

    /**
     * Pre-selects the agents laravel/boost is already set up for, so adopting
     * boost-core on a project that ran `boost:install` first doesn't mean picking
     * the same list a second time from memory.
     *
     * Deliberately narrow: the caller only asks when `boost.php` was SCAFFOLDED by
     * this run. An existing config is the operator's decision — including
     * `withAgents([])`, which reads identically to "not adopted yet" but means they
     * turned every agent off, and must not be repopulated from laravel/boost's state.
     * Nothing is written without confirmation either way; these are picker defaults,
     * and the operator can untick every one.
     *
     * @return array<string, true>  keyed by agent value, for defaults lookup
     */
    private function adoptableAgents(SymfonyStyle $io, string $projectRoot, InstalledPackages $packages): array
    {
        if (! $packages->has('laravel/boost')) {
            return [];
        }

        $state = (new LaravelBoostState())->agents($projectRoot);
        $values = array_map(static fn (Agent $agent): string => $agent->value, $state['agents']);

        $message = $values === []
            ? ''
            : sprintf(
                'laravel/boost is already set up for: %s. Those are pre-selected below — confirm or change them.',
                implode(', ', $values),
            );

        // Agents laravel/boost supports and boost-core does not can never be carried
        // over. Say so — including when they are ALL of them, which is exactly when a
        // silent no-op would look like boost-core ignoring an existing setup.
        if ($state['unmappable'] !== []) {
            $message = trim($message . sprintf(
                ' Not pre-selected (boost-core has no agent for these): %s.',
                implode(', ', $state['unmappable']),
            ));
        }

        if ($message !== '') {
            $io->note($message);
        }

        return array_fill_keys($values, true);
    }

    /**
     * @param  list<string>  $availableVendors
     * @return list<string>
     */
    private function pickVendors(SymfonyStyle $io, BoostConfig $config, array $availableVendors): array
    {
        if ($availableVendors === []) {
            // Don't prompt an empty list — but say WHY, so the operator isn't left
            // thinking install ignored vendors. "Publishes" = ships a
            // `resources/boost/skills` or `resources/boost/guidelines` dir.
            $io->note(
                'No installed Composer package publishes boost-core skills/guidelines '
                . '(a `resources/boost/skills` or `resources/boost/guidelines` directory), '
                . 'so the vendor allowlist picker was skipped. Your existing allowlist is kept.',
            );

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
    private function pickTags(SymfonyStyle $io, BoostConfig $config, array $vendors, InstalledPackages $packages): ?array
    {
        $available = (new AvailableTagsDiscovery($packages))->discover($vendors, $config->skillRenderers);
        if ($available === []) {
            // Same transparency as the vendor picker: explain the skip rather than
            // silently no-op (returning null leaves the existing withTags untouched).
            $io->note(
                'None of the selected vendors publish tagged skills/guidelines, so the '
                . 'tag picker was skipped. Your existing tags (if any) are kept.',
            );

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
     * laravel/boost ships its bundled skills + guidelines through the
     * project-boost-laravel WRAPPER's `project-boost:sync` injection, NOT boost-core's
     * vendor allowlist — so it never appears in the vendor picker. Say so on detection
     * (independent of whether the picker skipped) so the operator isn't left wondering
     * why laravel/boost isn't pickable. Points at `boost doctor` for the full picture.
     */
    private function noteLaravelBoostCoexistence(SymfonyStyle $io, InstalledPackages $packages): void
    {
        if (! $packages->has('laravel/boost')) {
            return;
        }

        // Branch on wrapper presence — without it, `project-boost:sync` does NOT
        // exist, so pointing the operator at it (right when they notice laravel/boost
        // is absent from the picker) would be a wrong next step.
        if (! $packages->has('sandermuller/project-boost-laravel')) {
            $io->note(
                'laravel/boost is installed, but the project-boost-laravel wrapper is NOT — so there is no '
                . "coexistence sync path yet, and laravel/boost's bundled skills/guidelines are not pickable "
                . 'here. Install sandermuller/project-boost-laravel to bridge them into boost-core (then sync '
                . 'with `php artisan project-boost:sync`). Run `boost doctor` for details.',
            );

            return;
        }

        $io->note(
            'laravel/boost is installed — its bundled skills + guidelines ship through the '
            . "project-boost-laravel wrapper (`php artisan project-boost:sync`), not boost-core's "
            . 'vendor allowlist, so it does not appear in the picker above. Run `boost doctor` for '
            . 'the full coexistence picture.',
        );
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
     * Where a fresh scaffold lands. Thin delegation to {@see ConfigScaffolder::target()},
     * kept as a named entry point because the install flow's location rules are
     * documented (and tested) against this command.
     */
    public static function scaffoldTarget(BoostConfigPath $resolved, bool $useConfigDir, string $projectRoot): string
    {
        return ConfigScaffolder::target($resolved, $useConfigDir, $projectRoot);
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
}
