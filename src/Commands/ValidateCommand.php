<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ValidateCommand extends BoostBaseCommand
{
    private const CLAUDE_MD = 'CLAUDE.md';

    protected function configure(): void
    {
        $this
            ->setName('boost:validate')
            ->setDescription("Validate CLAUDE.md Project Conventions against allowlisted vendors' schemas.")
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

        $allowedVendors = $config->allowedVendors;
        $discovery = new SchemaDiscovery(InstalledPackages::fromComposer());
        ['sources' => $sources, 'diagnostics' => $discoveryDiagnostics] = $discovery->discover($allowedVendors);

        $json = (bool) $input->getOption('json');
        $strict = (bool) $input->getOption('strict');
        $verboseInfo = $output->isVerbose();

        if ($sources === []) {
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

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = $discoveryDiagnostics;

        $claudeMdPath = $projectRoot . '/' . self::CLAUDE_MD;
        $claudeMd = is_file($claudeMdPath) ? file_get_contents($claudeMdPath) : null;
        if ($claudeMd === false) {
            $claudeMd = null;
        }

        $emitter = new ConventionsBlockEmitter();
        $extracted = $emitter->extract($claudeMd);
        if ($extracted === null) {
            $diagnostics[] = Diagnostic::warning(
                null,
                'no Project Conventions block found in CLAUDE.md — run `boost sync` to scaffold one',
            );

            return $this->render($io, $output, $diagnostics, $json, $strict, $verboseInfo);
        }

        ['values' => $values, 'diagnostics' => $parseDiagnostics] = $emitter->parse($claudeMd);
        $diagnostics = [...$diagnostics, ...$parseDiagnostics];
        if ($values === null) {
            return $this->render($io, $output, $diagnostics, $json, $strict, $verboseInfo);
        }

        $schema = new ConventionsSchema($sources);
        $diagnostics = [...$diagnostics, ...$schema->validate($values)];

        return $this->render($io, $output, $diagnostics, $json, $strict, $verboseInfo);
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
