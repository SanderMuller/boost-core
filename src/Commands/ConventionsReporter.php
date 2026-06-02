<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The `boost doctor --check-conventions` reporter — extracted from
 * {@see DoctorCommand} so the conventions discovery → validation → path-slot
 * existence subsystem lives in one cohesive collaborator instead of inflating
 * the command's cognitive complexity. Behavior is identical to the prior inline
 * `reportConventions()`; doctor delegates the whole opt-in check here.
 *
 * @internal
 */
final readonly class ConventionsReporter
{
    public function report(SymfonyStyle $io, string $projectRoot, BoostConfig $config, ?InstalledPackages $injectedPackages = null): void
    {
        $io->section('Project Conventions');

        $discovery = new SchemaDiscovery($injectedPackages ?? InstalledPackages::fromComposer());
        ['sources' => $sources, 'diagnostics' => $discoveryDiagnostics] = $discovery->discover($config->allowedVendors);

        if ($sources === []) {
            // Split malformed-declaration diagnostics (warning/error)
            // from the noise-collapse summary INFO. SchemaDiscovery's summary
            // INFO populates the diagnostics list even in the legitimately-
            // empty case ("no allowlisted vendor publishes a schema yet"), so
            // treating any diagnostic as malformed would false-positive: a clean
            // no-schemas-published project would triage as if every vendor
            // shipped broken JSON. Filter by level.
            $malformed = array_values(array_filter(
                $discoveryDiagnostics,
                static fn (Diagnostic $d): bool => $d->level !== 'info',
            ));

            if ($malformed === []) {
                $io->writeln('No conventions schemas declared by allowlisted vendors.');
                foreach ($discoveryDiagnostics as $diagnostic) {
                    $io->writeln("ℹ {$diagnostic->message}");
                }

                return;
            }

            $io->writeln('No usable conventions schemas — all declarations malformed:');
            foreach ($malformed as $diagnostic) {
                $vendor = $diagnostic->vendor === null ? '' : "[{$diagnostic->vendor}] ";
                $io->writeln("⚠ {$vendor}{$diagnostic->message}");
            }

            return;
        }

        // Source of truth is BoostConfig::$conventions, not CLAUDE.md.
        $values = $config->conventions;
        $schema = new ConventionsSchema($sources);
        $diagnostics = [
            ...$discoveryDiagnostics,
            ...$schema->validate($values),
            ...$this->checkPathSlots($projectRoot, $sources, $values),
        ];

        if ($diagnostics === []) {
            $io->success('Project Conventions valid against all allowlisted vendor schemas.');

            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $glyph = match ($diagnostic->level) {
                'error' => '✗',
                'warning' => '⚠',
                'info' => 'ℹ',
                default => ' ',
            };
            $slot = $diagnostic->slot === null ? '' : "{$diagnostic->slot}: ";
            $vendor = $diagnostic->vendor === null ? '' : " ({$diagnostic->vendor})";
            $io->writeln("{$glyph} {$slot}{$diagnostic->message}{$vendor}");
        }
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     * @param  array<mixed, mixed>  $values
     * @return list<Diagnostic>
     */
    private function checkPathSlots(string $projectRoot, array $sources, array $values): array
    {
        /** @var list<Diagnostic> $out */
        $out = [];
        $rootCanonical = realpath($projectRoot);
        if ($rootCanonical === false) {
            return $out;
        }

        foreach ($sources as $source) {
            $properties = is_array($source->schema['properties'] ?? null) ? $source->schema['properties'] : [];
            foreach ($properties as $name => $schema) {
                if (! is_string($name)) {
                    continue;
                }

                if (! is_array($schema)) {
                    continue;
                }

                foreach ($this->diagnosticsForSlot($projectRoot, $rootCanonical, $source->vendorName, $name, $schema, $values[$name] ?? null) as $diag) {
                    $out[] = $diag;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     * @return list<Diagnostic>
     */
    private function diagnosticsForSlot(string $projectRoot, string $rootCanonical, string $vendor, string $name, array $schema, mixed $value): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'string' && ($schema['format'] ?? null) === 'path' && is_string($value)) {
            $diagnostic = $this->checkSinglePath($projectRoot, $rootCanonical, $name, $value, $vendor);

            return $diagnostic instanceof Diagnostic ? [$diagnostic] : [];
        }

        if ($type !== 'array' || ! is_array($value)) {
            return [];
        }

        if (! is_array($schema['items'] ?? null) || ($schema['items']['format'] ?? null) !== 'path') {
            return [];
        }

        /** @var list<Diagnostic> $out */
        $out = [];
        foreach ($value as $index => $item) {
            if (! is_string($item)) {
                continue;
            }

            $diagnostic = $this->checkSinglePath($projectRoot, $rootCanonical, "{$name}[{$index}]", $item, $vendor);
            if ($diagnostic instanceof Diagnostic) {
                $out[] = $diagnostic;
            }
        }

        return $out;
    }

    private function checkSinglePath(string $projectRoot, string $rootCanonical, string $slot, string $value, string $vendor): ?Diagnostic
    {
        if ($value === '') {
            return Diagnostic::warning($slot, 'path slot has an empty value', $vendor);
        }

        $resolved = str_starts_with($value, '/') ? $value : $projectRoot . '/' . $value;
        $canonical = realpath($resolved);
        if ($canonical === false) {
            return Diagnostic::warning(
                $slot,
                "file '{$value}' not found",
                $vendor,
            );
        }

        if (! str_starts_with($canonical, $rootCanonical . '/') && $canonical !== $rootCanonical) {
            return Diagnostic::warning(
                $slot,
                "'{$value}' resolves outside project root ({$canonical})",
                $vendor,
            );
        }

        return null;
    }
}
