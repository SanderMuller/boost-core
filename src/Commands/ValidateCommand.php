<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\ConventionTokenLeakScanner;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Conventions\TokenLeak;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ValidateCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:validate')
            ->setDescription("Validate boost.php's withConventions([...]) against allowlisted vendors' schemas.")
            ->addWorkingDirOption()
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero (1) on any error-level diagnostic.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable diagnostics for CI tooling.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $config = $this->loadConfig($io, $projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $packages = InstalledPackages::fromComposer();
        $allowedVendors = $config->allowedVendors;
        $discovery = new SchemaDiscovery($packages);
        ['sources' => $sources, 'diagnostics' => $discoveryDiagnostics] = $discovery->discover($allowedVendors);

        $json = (bool) $input->getOption('json');
        $strict = (bool) $input->getOption('strict');
        $verboseInfo = $output->isVerbose();

        // 0.16.0 conventions-token observability: on-disk leak scan over the
        // EMITTED set (guidance + per-agent SKILL.md, incl. gitignored copies).
        // Runs regardless of declared schemas — a surviving `boost:conv` fence
        // opener is a leak with or without a live schema, and prose tokens still
        // classify (unknown-slot when no schema is composed). Each leak is an
        // error-level diagnostic, so `boost validate --strict` (the CI recipe)
        // hard-fails on a leaked token.
        $leakDiagnostics = $this->leakDiagnostics($projectRoot, $config, $packages);

        if ($sources === []) {
            if ($leakDiagnostics === []) {
                if ($json) {
                    $output->writeln(json_encode([
                        'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $discoveryDiagnostics),
                        'summary' => ['clean' => $discoveryDiagnostics === [], 'count' => count($discoveryDiagnostics)],
                    ], JSON_THROW_ON_ERROR));
                } else {
                    $io->info('no conventions schemas declared by any allowlisted vendor — nothing to validate');
                    foreach ($discoveryDiagnostics as $diagnostic) {
                        $output->writeln($this->formatLine($diagnostic));
                    }
                }

                return self::SUCCESS;
            }

            // No schema to validate against, but emitted output carries leaked
            // tokens — surface them through the normal render + exit path.
            return $this->render($io, $output, [...$discoveryDiagnostics, ...$leakDiagnostics], $json, $strict, $verboseInfo);
        }

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = $discoveryDiagnostics;

        // 0.9.0: source of truth is BoostConfig::$conventions, not CLAUDE.md.
        $schema = new ConventionsSchema($sources);
        $diagnostics = [...$diagnostics, ...$schema->validate($config->conventions), ...$leakDiagnostics];

        return $this->render($io, $output, $diagnostics, $json, $strict, $verboseInfo);
    }

    /**
     * On-disk conventions-token leak scan → error-level diagnostics (0.16.0).
     * One diagnostic per leaked token, slotted by the leaked path, with the
     * classified cause (resolves-but-unrendered → re-sync; resolver error → its
     * message; surviving fence opener → unprocessed-fence remedy).
     *
     * @return list<Diagnostic>
     */
    private function leakDiagnostics(string $projectRoot, BoostConfig $config, InstalledPackages $packages): array
    {
        $leaks = ConventionTokenLeakScanner::fromConfig($packages, $config)->scanEmitted($projectRoot, $config);

        return array_map(
            static fn (TokenLeak $leak): Diagnostic => Diagnostic::error(
                $leak->path,
                sprintf('leaked conventions token at %s — %s', $leak->location(), $leak->cause),
            ),
            $leaks,
        );
    }

    /**
     * @param  list<Diagnostic>  $diagnostics
     */
    private function render(SymfonyStyle $io, OutputInterface $output, array $diagnostics, bool $json, bool $strict, bool $verboseInfo): int
    {
        $hasError = false;
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                $hasError = true;
                break;
            }
        }

        if ($json) {
            $output->writeln(json_encode([
                'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $diagnostics),
                'summary' => ['clean' => $diagnostics === [], 'count' => count($diagnostics)],
            ], JSON_THROW_ON_ERROR));
        } elseif ($diagnostics === []) {
            $io->success('Project Conventions valid against all allowlisted vendor schemas.');
        } else {
            foreach ($diagnostics as $diagnostic) {
                if ($diagnostic->level === Diagnostic::LEVEL_INFO && ! $verboseInfo) {
                    continue;
                }

                $output->writeln($this->formatLine($diagnostic));
            }
        }

        return $strict && $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function formatLine(Diagnostic $diagnostic): string
    {
        $glyph = match ($diagnostic->level) {
            Diagnostic::LEVEL_ERROR => '<fg=red>✗</>',
            Diagnostic::LEVEL_WARNING => '<fg=yellow>⚠</>',
            Diagnostic::LEVEL_INFO => '<fg=cyan>ℹ</>',
            default => ' ',
        };

        $slot = $diagnostic->slot === null ? '' : "{$diagnostic->slot}: ";
        $vendor = $diagnostic->vendor === null ? '' : " (declared by {$diagnostic->vendor})";

        return "{$glyph} {$slot}{$diagnostic->message}{$vendor}";
    }
}
