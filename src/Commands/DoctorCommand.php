<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillTagDiagnostics;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Aggregated diagnostics for a boost-core install.
 */
final class DoctorCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:doctor')
            ->setDescription('Diagnose a boost-core install. Reports config, allowlist, drift, etc.');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $io->title('boost-core doctor');
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));
        $io->newLine();

        $config = $this->loadConfig($io, $projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $io->success(sprintf('boost.php at %s parses cleanly.', $projectRoot . '/boost.php'));

        $this->reportAgents($io, $config);
        $this->reportSourcePaths($io, $config);
        $this->reportAllowlist($io, $config);
        $this->reportTags($io, $config);
        $this->reportDrift($io, $projectRoot);

        return self::SUCCESS;
    }

    private function loadConfig(SymfonyStyle $io, string $projectRoot): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader())->load($projectRoot);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return null;
        } catch (Throwable $e) {
            $io->error('boost.php failed to load: ' . $e->getMessage());

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

    private function reportTags(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Skill tags');

        if ($config->tags === []) {
            $io->writeln('<comment>No tags declared. Every untagged skill ships; a tagged vendor skill is filtered out until you `withTags()` its tag.</comment>');
        } else {
            $io->writeln('Declared tags: <info>' . implode(', ', $config->tags) . '</info>');
        }

        $loader = new SkillLoader(new FrontmatterParser());
        $scanner = new VendorScanner(InstalledPackages::fromComposer());
        $diagnostics = new SkillTagDiagnostics();

        /** @var list<array{0: string, 1: string, 2: string}> $rows */
        $rows = [];
        /** @var array<string, true> $skillTagUnion */
        $skillTagUnion = [];

        foreach ($scanner->discover() as $vendor) {
            if (! $config->isVendorAllowed($vendor->name)) {
                continue;
            }

            if ($vendor->skillsPath === null) {
                continue;
            }

            foreach ($loader->load($vendor->skillsPath, $vendor->name) as $skill) {
                foreach ($skill->tags as $tag) {
                    $skillTagUnion[$tag] = true;
                }

                $rows[] = [$vendor->name, $skill->name, $diagnostics->status($skill, $config)];
            }
        }

        if ($rows === []) {
            $io->writeln('<info>No allowlisted vendor skills installed.</info>');

            return;
        }

        $io->table(['Vendor', 'Skill', 'Tag status'], $rows);
        $this->reportTagHygiene($io, $config, array_keys($skillTagUnion), $diagnostics);
    }

    /**
     * @param  list<string>  $skillTagUnion  Every tag declared by an installed allowlisted skill.
     */
    private function reportTagHygiene(
        SymfonyStyle $io,
        BoostConfig $config,
        array $skillTagUnion,
        SkillTagDiagnostics $diagnostics,
    ): void {
        if ($skillTagUnion !== []) {
            $io->writeln('Tags in use by installed skills: <info>' . implode(', ', $skillTagUnion) . '</info>');
        }

        $declaredButUnused = $diagnostics->declaredButUnusedTags($config, $skillTagUnion);
        if ($declaredButUnused !== []) {
            $io->writeln('<comment>Declared tags matched by no installed skill (possible typo): '
                . implode(', ', $declaredButUnused) . '</comment>');
        }

        foreach ($diagnostics->nearDuplicates([...$skillTagUnion, ...$config->tags]) as $pair) {
            $io->writeln('<comment>Possible tag typo — these look alike: '
                . $pair[0] . ' / ' . $pair[1] . '</comment>');
        }
    }

    private function reportDrift(SymfonyStyle $io, string $projectRoot): void
    {
        $io->section('Drift');

        try {
            $result = SyncEngine::default()->sync($projectRoot, checkOnly: true);
        } catch (Throwable $throwable) {
            $io->warning('Could not check drift: ' . $throwable->getMessage());

            return;
        }

        if ($result->hasDrift()) {
            $io->warning(sprintf(
                '%d file(s) would change. Run `composer boost:sync`.',
                $result->countWouldChange(),
            ));

            return;
        }

        $io->success('No drift detected. Generated files match sources.');
    }
}
