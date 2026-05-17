<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\Command\BaseCommand;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
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
 * First-party packages (matching FirstPartyPrefixes) are pre-checked.
 */
final class InstallCommand extends BaseCommand
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
            ->setDescription('Interactive picker: choose agents and allowlist vendors. Updates boost.php.')
            ->addOption(
                'working-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Project root. Defaults to current working directory.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workingDir = $input->getOption('working-dir');
        if (is_string($workingDir)) {
            $projectRoot = $workingDir;
        } else {
            $cwd = getcwd();
            $projectRoot = $cwd === false ? '.' : $cwd;
        }

        $projectRoot = rtrim($projectRoot, '/');
        $configPath = $projectRoot . '/boost.php';

        try {
            $config = $this->loader->load($projectRoot);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $packages = InstalledPackages::fromComposer();
        $availableVendors = $this->discoverPublishers($packages);

        $agents = $this->pickAgents($config);
        $vendors = $this->pickVendors($config, $availableVendors);

        try {
            $this->writer->update(
                $configPath,
                $agents,
                $vendors,
                $config->disabledEmitters,
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Updated %s', $configPath));
        $io->writeln('Next: run <info>composer boost:sync</info> to regenerate agent files.');

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

        return array_map(static fn (string $value): Agent => Agent::from($value), $picked);
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
