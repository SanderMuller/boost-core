<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigLoader;
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
 */
final class ScanCommand extends BoostBaseCommand
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
            ->setName('boost:scan')
            ->setDescription('Re-run the vendor allowlist picker. Use after installing new packages that publish skills/guidelines.');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);
        $configPath = $projectRoot . '/boost.php';

        try {
            $config = $this->loader->load($projectRoot);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $packages = InstalledPackages::fromComposer();
        $scanner = new VendorScanner($packages);
        $availableVendors = [];
        foreach ($scanner->discover() as $discovered) {
            $availableVendors[] = $discovered->name;
        }

        if ($availableVendors === []) {
            $io->note('No installed packages publish skills/guidelines yet. Install some, then re-run.');

            return self::SUCCESS;
        }

        $options = [];
        $defaults = [];
        foreach ($availableVendors as $vendorName) {
            $options[$vendorName] = $vendorName;
            if ($config->isVendorAllowed($vendorName) || $this->firstParty->matches($vendorName)) {
                $defaults[] = $vendorName;
            }
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
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Updated allowlist in %s', $configPath));
        $io->writeln('Next: run <info>composer boost:sync</info> to regenerate agent files.');

        return self::SUCCESS;
    }
}
