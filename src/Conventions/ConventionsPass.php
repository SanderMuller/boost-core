<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Sync\InstalledPackages;

/**
 * The conventions build for one sync transaction (0.15.0 inlining / 0.16.0
 * observability), extracted from SyncEngine (maintenance cycle 2026-05).
 *
 * Discovers the conventions schema ONCE and builds the collaborators the sync
 * needs: the slot {@see ConventionsInliner} (run over vendor + host skills AND
 * assembled guidance), the rendered `## Project Conventions` `section` (null when
 * no allowlisted vendor ships a schema; the drop-gate later decides whether it is
 * actually written), discovery `diagnostics`, and the {@see ConventionTokenLeakScanner}.
 *
 * This is the single conventions-build authority — `ConventionTokenLeakScanner::
 * fromConfig()` delegates here, so the build is defined once (previously this
 * logic was duplicated between SyncEngine::conventionsContext and that factory).
 */
final readonly class ConventionsPass
{
    /**
     * @param  list<Diagnostic>  $diagnostics  schema discovery + validation diagnostics
     */
    private function __construct(
        private ConventionsInliner $inliner,
        private SlotResolver $resolver,
        private ?string $section,
        private array $diagnostics,
    ) {}

    public static function build(InstalledPackages $packages, BoostConfig $config): self
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        ['sources' => $sources, 'diagnostics' => $convDiagnostics] = (new SchemaDiscovery($packages))->discover(
            $config->allowedVendors,
            conventionsDeclared: $config->conventions !== [],
        );
        $diagnostics = [...$diagnostics, ...$convDiagnostics];

        /** @var array<string, mixed> $composed */
        $composed = [];
        $section = null;
        if ($sources !== []) {
            $schema = new ConventionsSchema($sources);
            $diagnostics = [...$diagnostics, ...$schema->validate($config->conventions)];
            $composed = $schema->compose();
            $seed = (new ConventionsBlockEmitter())->scaffoldSeed($sources);
            $section = (new GuidanceComposer())->renderConventionsSection($config->conventions, $seed);
        }

        /** @var list<string> $slotRoots */
        $slotRoots = [];
        $properties = $composed['properties'] ?? null;
        if (is_array($properties)) {
            foreach (array_keys($properties) as $root) {
                if (is_string($root) && $root !== 'schema-version') {
                    $slotRoots[] = $root;
                }
            }
        }

        $resolver = new SlotResolver($config->conventions, $composed);

        return new self(new ConventionsInliner($resolver, $slotRoots), $resolver, $section, $diagnostics);
    }

    public function inliner(): ConventionsInliner
    {
        return $this->inliner;
    }

    /**
     * The rendered `## Project Conventions` block, or null when no allowlisted
     * vendor ships a schema. The drop-gate decides whether it is actually written.
     */
    public function section(): ?string
    {
        return $this->section;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function leakScanner(): ConventionTokenLeakScanner
    {
        return new ConventionTokenLeakScanner($this->inliner, $this->resolver);
    }
}
