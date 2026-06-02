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
 *
 * @internal
 */
final readonly class SchemaDiscovery
{
    private const SCHEMA_REL_PATH = 'resources/boost/conventions-schema.json';

    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * @param  list<string>  $allowedVendors  in operator-declared order
     * @param  bool  $conventionsDeclared  whether the consumer declared
     *   `->withConventions([...])`. Together with whether any vendor ships a
     *   schema, this gates the "N vendors ship no conventions-schema.json"
     *   summary INFO: when the conventions subsystem is DORMANT (no declared
     *   conventions AND no vendor schema) the INFO is pure noise — a skills-only
     *   vendor simply has no conventions feature — so it is suppressed. The
     *   conventions-audit commands (`validate` / `slots`) pass the default
     *   `true` so the INFO always surfaces there.
     * @return array{sources: list<VendorSchemaSource>, diagnostics: list<Diagnostic>}
     */
    public function discover(array $allowedVendors, bool $conventionsDeclared = true): array
    {
        /** @var list<VendorSchemaSource> $sources */
        $sources = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        /** @var list<string> $noSchemaVendors Accumulated for the noise-collapse summary diagnostic */
        $noSchemaVendors = [];
        /** @var int $allowedCount Vendors actually inspected (excludes self-referential + uninstalled) */
        $allowedCount = 0;
        // True once ANY vendor ships a conventions-schema.json file — whether it
        // loads cleanly into $sources or fails to read/parse (a warning). Either
        // way the conventions subsystem is in play, so the dormancy gate below
        // must not suppress the "these vendors ship no schema" context.
        $schemaFileSeen = false;

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
                // Accumulate vendor names instead of
                // emitting one INFO per vendor. The N-vendor sync noise
                // inverts the signal/noise
                // ratio when most allowlisted vendors don't ship a schema
                // (the common case — conventions-schema is a niche feature).
                $noSchemaVendors[] = $vendorName;

                continue;
            }

            $schemaFileSeen = true;
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

        // Emit ONE summary INFO instead of N per-vendor
        // INFOs. The schema-discovery pass is informational; per-vendor detail
        // is surfaced via `boost doctor` (the vendor allowlist section already
        // lists every allowlisted vendor) when operators need it for triage.
        //
        // Wording is stateless w.r.t. whether OTHER allowlisted vendors did
        // ship a schema. Stating only "N of M ship no schema"
        // + the triage pointer is correct regardless of whether $sources is
        // empty or populated.
        // Dormancy gate: suppress the summary INFO when the conventions
        // subsystem isn't in play at all — no declared conventions AND no vendor
        // shipped a schema. In that state a skills-only vendor's absence-of-
        // schema is not a signal; surfacing it just reads as worrisome noise
        // ("what's wrong?") when nothing is. The INFO still fires when the
        // operator declared conventions (they're trying to use the feature, so
        // a missing schema is actionable) or when at least one vendor shipped a
        // schema FILE — loaded OR broken (the "these others don't" context is
        // then meaningful, and a malformed schema still proves the subsystem is
        // in play even though it never reached $sources).
        if ($noSchemaVendors !== [] && ($conventionsDeclared || $schemaFileSeen)) {
            $diagnostics[] = Diagnostic::info(
                null,
                sprintf(
                    '%d of %d allowlisted vendor(s) ship no conventions-schema.json. Inspect `boost doctor` vendor allowlist section for the per-vendor list.',
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
