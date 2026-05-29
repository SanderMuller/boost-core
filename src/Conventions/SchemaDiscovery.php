<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use JsonException;
use SanderMuller\BoostCore\Sync\InstalledPackages;

/**
 * Walks installed Composer packages and loads each allowlisted vendor's
 * `resources/boost/conventions-schema.json` where present.
 *
 * Walks InstalledPackages directly rather than VendorScanner because a vendor
 * may ship ONLY a conventions-schema (no skills/guidelines) — VendorScanner
 * filters to vendors with publishable content, which is the wrong gate here.
 *
 * Lenient on parse failure: malformed schemas return a `warning` Diagnostic
 * via the returned list, vendor is skipped from the `sources` set, sync
 * continues. Same channel as operator YAML errors (see spec §13).
 *
 * Scope limit: composer-installed vendors only. Injection-path vendors
 * (SyncEngine::injectedVendorSkills) are not covered — see spec §3.4.
 */
final readonly class SchemaDiscovery
{
    private const SCHEMA_REL_PATH = 'resources/boost/conventions-schema.json';

    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * @param  list<string>  $allowedVendors  in operator-declared order
     * @return array{sources: list<VendorSchemaSource>, diagnostics: list<Diagnostic>}
     */
    public function discover(array $allowedVendors): array
    {
        /** @var list<VendorSchemaSource> $sources */
        $sources = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        /** @var list<string> $noSchemaVendors Accumulated for the 0.10.1 noise-collapse summary diagnostic */
        $noSchemaVendors = [];
        /** @var int $allowedCount Vendors actually inspected (excludes self-referential + uninstalled) */
        $allowedCount = 0;

        foreach ($allowedVendors as $vendorName) {
            // Skip self-referential check: boost-core is the engine, it doesn't
            // ship a conventions catalog. Without this guard, every sync emits
            // a noise INFO diagnostic for boost-core's own absence-of-schema,
            // which inverts the signal/noise ratio when boost-core is
            // self-allowlisted (the common case for dogfood + tooling-author
            // projects).
            if ($vendorName === 'sandermuller/boost-core') {
                continue;
            }

            if (! $this->packages->has($vendorName)) {
                continue;
            }

            ++$allowedCount;
            $installPath = $this->packages->path($vendorName);
            $schemaPath = $installPath . '/' . self::SCHEMA_REL_PATH;
            if (! is_file($schemaPath)) {
                // 0.10.1 noise collapse: accumulate vendor names instead of
                // emitting one INFO per vendor. The N-vendor sync noise that
                // adoption dogfood flagged was inverting the signal/noise
                // ratio when most allowlisted vendors don't ship a schema
                // (the common case — conventions-schema is a niche feature).
                $noSchemaVendors[] = $vendorName;

                continue;
            }

            $raw = @file_get_contents($schemaPath);
            if ($raw === false) {
                $diagnostics[] = Diagnostic::warning(
                    null,
                    'conventions-schema.json could not be read at ' . $schemaPath,
                    $vendorName,
                );

                continue;
            }

            try {
                /** @var array<mixed, mixed> $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $diagnostics[] = Diagnostic::warning(
                    null,
                    'conventions-schema.json failed to parse: ' . $e->getMessage(),
                    $vendorName,
                );

                continue;
            }

            if (! is_array($decoded)) {
                $diagnostics[] = Diagnostic::warning(
                    null,
                    'conventions-schema.json must decode to a JSON object',
                    $vendorName,
                );

                continue;
            }

            $sources[] = new VendorSchemaSource(
                vendorName: $vendorName,
                schemaPath: $schemaPath,
                schema: $decoded,
            );
        }

        // 0.10.1 noise-collapse: emit ONE summary INFO instead of N per-vendor
        // INFOs. The schema-discovery pass is informational; the load-bearing
        // signal is "did the engine find any schemas?" not "which specific
        // vendors don't ship one?" Per-vendor detail is surfaced via
        // `boost doctor` (the vendor allowlist section already lists every
        // allowlisted vendor) when operators need it for triage.
        if ($noSchemaVendors !== []) {
            $diagnostics[] = Diagnostic::info(
                null,
                sprintf(
                    '%d of %d allowlisted vendor(s) ship no conventions-schema.json. The conventions-schema layer is dormant until at least one vendor publishes one. Inspect `boost doctor` vendor allowlist section for the per-vendor list.',
                    count($noSchemaVendors),
                    $allowedCount,
                ),
            );
        }

        return [
            'sources' => $sources,
            'diagnostics' => $diagnostics,
        ];
    }
}
