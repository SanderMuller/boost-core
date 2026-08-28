<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Discovery\FirstPartyPrefixes;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function Laravel\Prompts\multiselect;

/**
 * Re-run the vendor allowlist picker only. Use after installing new
 * Composer packages that publish skills/guidelines.
 *
 * Distinguished from boost:install (which also picks agents) — scan is
 * narrower and intended for the common "new dep, want to allowlist it"
 * workflow.
 *
 * @internal
 */
final class ScanCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly BoostConfigLoader $loader = new BoostConfigLoader(),
        private readonly BoostConfigWriter $writer = new BoostConfigWriter(),
        private readonly FirstPartyPrefixes $firstParty = new FirstPartyPrefixes(),
        // Injection seam for tests — null means "read the real Composer
        // runtime via InstalledPackages::fromComposer()". Mirrors
        // DoctorCommand's `$injectedPackages`.
        private readonly ?InstalledPackages $injectedPackages = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:scan')
            ->setDescription('Re-run the vendor allowlist picker. Use after installing new packages that publish skills/guidelines.');
        $this->addWorkingDirOption();
        $this->addConfigOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);
        $configOverride = $this->configFileOption($input);

        try {
            $config = $this->loader->load($projectRoot, $configOverride);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        // Write back to the file the config was loaded from (root or
        // .config/boost.php), not a hardcoded root path. Safe post-load — an
        // ambiguous/missing config already errored above.
        $configPath = BoostConfigPath::resolve($projectRoot, $configOverride)->path;

        $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
        $scanner = new VendorScanner($packages);
        $availableVendors = [];
        foreach ($scanner->discover() as $discovered) {
            $availableVendors[] = $discovered->name;
        }

        // Union in vendors the config ALREADY allows that the scanner cannot
        // see. Without this the picker can only offer what `discover()` found,
        // and `BoostConfigWriter` replaces `withAllowedVendors()` wholesale with
        // the picked subset — so an allowed-but-undiscovered entry was not
        // merely deselected by default, it was impossible to keep, and scan
        // silently narrowed a hand-authored config.
        //
        // A vendor is legitimately allowed-but-undiscovered when its content
        // only exists at a later stage than a bare scan can observe (a wrapper
        // package injects it) or when the package was removed but the entry
        // was left behind. Both are the operator's call to make, so both stay
        // visible and preselected. Scan never drops an entry on its own.
        $undiscoverableAllowed = [];
        foreach ($config->allowedVendors as $allowedVendor) {
            if (! in_array($allowedVendor, $availableVendors, strict: true)) {
                $undiscoverableAllowed[] = $allowedVendor;
            }
        }

        if ($availableVendors === [] && $undiscoverableAllowed === []) {
            $io->note('No installed packages publish skills/guidelines yet. Install some, then re-run.');

            return self::SUCCESS;
        }

        // The vendor picker needs a TTY — fail fast with guidance rather than
        // hanging on a prompt under CI / --no-interaction.
        if (! $this->isInteractiveOrExplain($input, $io, "`boost scan`'s vendor picker needs an interactive terminal (an attached TTY, and no --no-interaction). CI jobs, git hooks, Composer scripts and agent shells have no terminal to prompt in. Edit ->withAllowedVendors([...]) in boost.php directly instead.")) {
            return self::FAILURE;
        }

        $options = [];
        $defaults = [];
        foreach ($availableVendors as $vendorName) {
            $options[$vendorName] = $vendorName;
            if ($config->isVendorAllowed($vendorName) || $this->firstParty->matches($vendorName)) {
                $defaults[] = $vendorName;
            }
        }

        foreach ($undiscoverableAllowed as $vendorName) {
            // Labelled so the operator can tell why it is on the list, and
            // preselected so pressing enter keeps the config as authored.
            $options[$vendorName] = $vendorName . ' (already allowlisted — publishes nothing this scan can see)';
            $defaults[] = $vendorName;
        }

        /** @var list<string> $picked */
        $picked = multiselect(
            label: 'Which installed vendor packages should publish skills/guidelines?',
            options: $options,
            default: $defaults,
            hint: 'Space to toggle, enter to confirm.',
        );

        try {
            $this->writer->update($configPath, $config->agents, $picked, $config->disabledEmitters);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Updated allowlist in %s', $configPath));
        $io->writeln('Next: run <info>vendor/bin/boost sync</info> to regenerate agent files.');

        return self::SUCCESS;
    }
}
