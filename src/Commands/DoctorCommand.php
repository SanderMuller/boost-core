<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\Command\BaseCommand;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Aggregated diagnostics for a boost-core install.
 */
final class DoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:doctor')
            ->setDescription('Diagnose a boost-core install. Reports config, allowlist, drift, etc.')
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
        $projectRoot = $this->resolveProjectRoot($input);

        $io->title('boost-core doctor');
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));
        $io->newLine();

        $config = $this->loadConfig($io, $projectRoot);
        if ($config === null) {
            return self::FAILURE;
        }

        $io->success(sprintf('boost.php at %s parses cleanly.', $projectRoot.'/boost.php'));

        $this->reportAgents($io, $config);
        $this->reportSourcePaths($io, $config);
        $this->reportAllowlist($io, $config);
        $this->reportDrift($io, $projectRoot);

        return self::SUCCESS;
    }

    private function resolveProjectRoot(InputInterface $input): string
    {
        $workingDir = $input->getOption('working-dir');
        if (is_string($workingDir)) {
            return rtrim($workingDir, '/');
        }
        $cwd = getcwd();

        return rtrim($cwd === false ? '.' : $cwd, '/');
    }

    private function loadConfig(SymfonyStyle $io, string $projectRoot): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader)->load($projectRoot);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return null;
        } catch (Throwable $e) {
            $io->error('boost.php failed to load: '.$e->getMessage());

            return null;
        }
    }

    private function reportAgents(SymfonyStyle $io, BoostConfig $config): void
    {
        $agents = array_map(static fn (Agent $a): string => $a->value, $config->agents);
        $io->section('Agents');
        if ($agents === []) {
            $io->warning('No agents configured. Run `composer boost:install` to pick.');

            return;
        }
        $io->listing($agents);
    }

    private function reportSourcePaths(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Source paths');
        $io->table(['Key', 'Path', 'Status'], [
            ['skillsPath', $config->skillsPath, is_dir($config->skillsPath) ? 'exists' : 'MISSING'],
            ['guidelinesPath', $config->guidelinesPath, is_dir($config->guidelinesPath) ? 'exists' : 'MISSING'],
        ]);
    }

    private function reportAllowlist(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Vendor allowlist');

        $packages = InstalledPackages::fromComposer();
        $scanner = new VendorScanner($packages);
        $discoveredNames = [];
        foreach ($scanner->discover() as $vendor) {
            $discoveredNames[] = $vendor->name;
        }

        $allowedAndPresent = [];
        $allowedButMissing = [];
        foreach ($config->allowedVendors as $vendor) {
            if (in_array($vendor, $discoveredNames, true)) {
                $allowedAndPresent[] = $vendor;
            } else {
                $allowedButMissing[] = $vendor;
            }
        }
        $discoveredButNotAllowed = array_values(array_diff($discoveredNames, $config->allowedVendors));

        $this->renderAllowlistGroup($io, 'Allowlisted and publishing', $allowedAndPresent, 'info');
        $this->renderAllowlistGroup($io, 'Allowlisted but not installed (or not publishing)', $allowedButMissing, 'comment');
        $this->renderAllowlistGroup($io, 'Discovered but NOT allowlisted (run `composer boost:scan` to opt in)', $discoveredButNotAllowed, 'comment');

        if ($allowedAndPresent === [] && $allowedButMissing === [] && $discoveredButNotAllowed === []) {
            $io->writeln('<info>No vendor publishers detected.</info>');
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function renderAllowlistGroup(SymfonyStyle $io, string $label, array $items, string $tag): void
    {
        if ($items === []) {
            return;
        }
        $io->writeln(sprintf('<%s>%s:</%s>', $tag, $label, $tag));
        $io->listing($items);
    }

    private function reportDrift(SymfonyStyle $io, string $projectRoot): void
    {
        $io->section('Drift');

        try {
            $result = SyncEngine::default()->sync($projectRoot, checkOnly: true);
        } catch (Throwable $e) {
            $io->warning('Could not check drift: '.$e->getMessage());

            return;
        }

        if ($result->hasDrift()) {
            $io->warning(sprintf(
                '%d file(s) would change. Run `composer boost:sync`.',
                $result->countByAction(WriteAction::WOULD_WRITE),
            ));

            return;
        }

        $io->success('No drift detected. Generated files match sources.');
    }
}
