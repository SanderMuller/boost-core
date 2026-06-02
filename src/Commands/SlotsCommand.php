<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final class SlotsCommand extends BoostBaseCommand
{
    private const USAGE_EXIT = 2;

    protected function configure(): void
    {
        $this
            ->setName('boost:slots')
            ->setDescription('List conventions slots across allowlisted vendors (origin-traced).')
            ->addWorkingDirOption()
            ->addOption('vendor', null, InputOption::VALUE_REQUIRED, 'Filter to one vendor.')
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Show only required-but-unfilled slots.')
            ->addOption('filled', null, InputOption::VALUE_NONE, 'Show only slots with a current value.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output for CI tooling.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $missing = (bool) $input->getOption('missing');
        $filled = (bool) $input->getOption('filled');
        if ($missing && $filled) {
            $io->error('--missing and --filled are mutually exclusive');

            return self::USAGE_EXIT;
        }

        $projectRoot = $this->resolveProjectRoot($input);
        $config = $this->loadConfig($io, $projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $allowedVendors = $config->allowedVendors;
        $vendorFilter = $input->getOption('vendor');
        $vendorFilter = is_string($vendorFilter) ? $vendorFilter : null;

        $discovery = new SchemaDiscovery(InstalledPackages::fromComposer());
        ['sources' => $sources, 'diagnostics' => $discoveryDiagnostics] = $discovery->discover($allowedVendors);

        $json = (bool) $input->getOption('json');

        if ($sources === []) {
            $this->emitEmpty($io, $output, $json, 'no conventions schemas declared by any allowlisted vendor — nothing to list');
            foreach ($discoveryDiagnostics as $diagnostic) {
                $output->writeln(sprintf(
                    '<fg=yellow>⚠</> %s%s',
                    $diagnostic->vendor === null ? '' : "[{$diagnostic->vendor}] ",
                    $diagnostic->message,
                ));
            }

            return self::SUCCESS;
        }

        if ($vendorFilter !== null && ! $this->vendorInSources($sources, $vendorFilter)) {
            $this->emitEmpty($io, $output, $json, "vendor {$vendorFilter} not in allowlist");

            return self::SUCCESS;
        }

        // Source of truth is BoostConfig::$conventions, not CLAUDE.md.
        $slots = $this->collectSlots($sources, $vendorFilter, $config->conventions, $missing, $filled);

        if ($json) {
            $output->writeln(json_encode(['slots' => $slots], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($slots === []) {
            $io->info($missing ? 'all required slots filled' : 'no slots match');

            return self::SUCCESS;
        }

        foreach ($slots as $slot) {
            $output->writeln($this->formatSlot($slot));
        }

        return self::SUCCESS;
    }

    private function emitEmpty(SymfonyStyle $io, OutputInterface $output, bool $json, string $message): void
    {
        if ($json) {
            $output->writeln(json_encode(['slots' => []], JSON_THROW_ON_ERROR));

            return;
        }

        $io->writeln($message);
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     */
    private function vendorInSources(array $sources, string $vendor): bool
    {
        foreach ($sources as $source) {
            if ($source->vendorName === $vendor) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     * @param  array<mixed, mixed>  $hostValues
     * @return list<array{path: string, type: string, required: bool, default: mixed, value: mixed, description: ?string, vendor: string}>
     */
    private function collectSlots(array $sources, ?string $vendorFilter, array $hostValues, bool $missing, bool $filled): array
    {
        /** @var list<array{path: string, type: string, required: bool, default: mixed, value: mixed, description: ?string, vendor: string}> $out */
        $out = [];
        foreach ($sources as $source) {
            if ($vendorFilter !== null && $source->vendorName !== $vendorFilter) {
                continue;
            }

            $required = is_array($source->schema['required'] ?? null) ? $source->schema['required'] : [];
            $requiredSet = array_flip(array_filter($required, is_string(...)));

            $properties = is_array($source->schema['properties'] ?? null) ? $source->schema['properties'] : [];
            foreach ($properties as $name => $schema) {
                if (! is_string($name)) {
                    continue;
                }

                if ($name === 'schema-version') {
                    continue;
                }

                if (! is_array($schema)) {
                    continue;
                }

                $isRequired = isset($requiredSet[$name]);
                $value = $hostValues[$name] ?? null;
                $isFilled = $value !== null;

                if ($missing && ($isFilled || ! $isRequired)) {
                    continue;
                }

                if ($filled && ! $isFilled) {
                    continue;
                }

                $out[] = [
                    'path' => $name,
                    'type' => $this->schemaType($schema),
                    'required' => $isRequired,
                    'default' => $schema['default'] ?? null,
                    'value' => $value,
                    'description' => is_string($schema['description'] ?? null) ? $schema['description'] : null,
                    'vendor' => $source->vendorName,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     */
    private function schemaType(array $schema): string
    {
        $type = $schema['type'] ?? null;
        if (is_string($type)) {
            return $type;
        }

        if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            return 'mixed';
        }

        return 'unknown';
    }

    /**
     * @param  array{path: string, type: string, required: bool, default: mixed, value: mixed, description: ?string, vendor: string}  $slot
     */
    private function formatSlot(array $slot): string
    {
        $req = $slot['required'] ? '<fg=yellow>required</>' : 'optional';
        $value = $slot['value'] === null ? '<fg=red>unfilled</>' : '<fg=green>set</>';

        return sprintf(
            '%s (%s, %s) [%s] — %s',
            $slot['path'],
            $slot['type'],
            $req,
            $slot['vendor'],
            $value,
        );
    }
}
