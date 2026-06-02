<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use JsonException;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use Throwable;

/**
 * Drives `boost sync --scope=user --all`: user-scope sync every installed
 * package that ships `resources/boost/skills/`.
 *
 * Lives outside {@see SyncEngine} so the per-package loop + collision
 * guard do not load the engine's class cognitive-complexity budget.
 *
 * One package failing — or losing a user-scope-path collision — does not
 * abort the rest; each yields its own {@see UserScopeResult}.
 */
final class UserScopeBulkSync
{
    /**
     * @return list<UserScopeResult>  one per skill-shipping package
     */
    public function run(SyncEngine $engine, VendorScanner $vendorScanner, bool $checkOnly, ?string $homeRoot): array
    {
        $home = $homeRoot !== null ? rtrim($homeRoot, '/') : SyncEngine::resolveHomeDirectory();

        /** @var list<UserScopeResult> $results */
        $results = [];
        /** @var array<string, string> $claimedSuffixes  user-scope suffix => package name */
        $claimedSuffixes = [];
        // Every package discovered present THIS run, keyed by user-scope slug.
        // `$discoveredWithSkills` is the subset that still ships skills — those are
        // synced below and must never be reconcile-reaped, even if their manifest's
        // recorded install path is stale (e.g. a move + a failed manifest rewrite).
        /** @var array<string, true> $discovered */
        $discovered = [];
        /** @var array<string, true> $discoveredWithSkills */
        $discoveredWithSkills = [];

        foreach ($vendorScanner->discover() as $vendor) {
            $discovered[SyncEngine::packageSuffix($vendor->name)] = true;

            if ($vendor->skillsPath === null) {
                continue;
            }

            $discoveredWithSkills[SyncEngine::packageSuffix($vendor->name)] = true;

            // Defensive: `packageSuffix` is injective for valid Composer
            // names, so this never fires in practice — a guardrail against
            // a future suffix-scheme regression silently merging two
            // packages' user-scope output.
            $suffix = SyncEngine::packageSuffix($vendor->name);
            if (isset($claimedSuffixes[$suffix])) {
                $results[] = new UserScopeResult(
                    packageName: $vendor->name,
                    homeRoot: $home,
                    writes: [],
                    errors: [sprintf('Skipped — user-scope path "%s" already claimed by %s.', $suffix, $claimedSuffixes[$suffix])],
                    check: $checkOnly,
                );

                continue;
            }

            $claimedSuffixes[$suffix] = $vendor->name;

            try {
                $results[] = $engine->syncUser($vendor->installPath, $checkOnly, $home);
            } catch (Throwable $throwable) {
                $results[] = new UserScopeResult(
                    packageName: $vendor->name,
                    homeRoot: $home,
                    writes: [],
                    errors: [$throwable->getMessage()],
                    check: $checkOnly,
                );
            }
        }

        foreach ($this->reconcileRemoved($home, $checkOnly, $discovered, $discoveredWithSkills) as $result) {
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Reconcile-on-remove: reap the user-scope files of packages that have been
     * `composer global remove`d. Scans every per-package manifest under
     * `$home/.boost/manifests/`; a package is "removed" iff its recorded install
     * path no longer exists ON DISK — NOT merely if it's absent from this run's
     * discovered set, which a wrong-Composer-context `--all` could get wrong and
     * thereby mass-reap a still-installed package's home dir. The reap is
     * sha-gated + slug-validated by {@see UserScopeReaper}; on a clean (non-check,
     * non-retained) reap the now-stale manifest is deleted, else kept for retry.
     *
     * @param  array<string, true>  $discovered  user-scope slugs of every package present this run
     * @param  array<string, true>  $discoveredWithSkills  subset that still ships skills (synced above)
     * @return list<UserScopeResult>  one per removed package whose files were reaped
     */
    private function reconcileRemoved(string $home, bool $checkOnly, array $discovered, array $discoveredWithSkills): array
    {
        $dir = $home . '/' . UserScopeManifest::DIR;
        if (! is_dir($dir)) {
            return [];
        }

        $reaper = new UserScopeReaper();
        /** @var list<UserScopeResult> $results */
        $results = [];

        $manifestFiles = glob($dir . '/*.json');
        if ($manifestFiles === false) {
            return [];
        }

        foreach ($manifestFiles as $file) {
            $manifest = UserScopeManifest::fromFile($file);
            $slug = basename($file, '.json');

            // Discovered present AND still ships skills → the per-package syncUser
            // pass above owns its reconcile. Skip on the DISCOVERY signal, NOT the
            // manifest's recorded install path: a package that moved install paths
            // and whose sync failed before rewriting its manifest still has the OLD
            // (now-gone) path recorded, which would otherwise look "removed" and
            // reap its freshly-synced files (codex 0.19.0).
            if (isset($discoveredWithSkills[$slug])) {
                continue;
            }

            // Not discovered at all → fall back to the recorded install path to
            // decide removal. No path (pre-0.19 / corrupt) → can't prove removal,
            // never reap. Path present AND still ships skills → present but
            // undiscovered (e.g. a project-local `--all` that can't see the global
            // package) → skip, preserving wrong-context safety. Otherwise (path
            // gone, or present but skills dir dropped) → reap.
            //
            // A package discovered WITHOUT skills (dropped ALL its skills) is NOT
            // in $discoveredWithSkills and falls straight through to the reap.
            if (! isset($discovered[$slug])) {
                if ($manifest->installPath === '') {
                    continue;
                }

                // Path present AND still ships skills AND still hosts THIS package
                // → present-but-undiscovered, skip. The package-identity check
                // matters for rename/replace-in-place: a different package shipping
                // skills at the same path leaves the old slug's tree orphaned, so
                // it must be reaped here rather than skipped (codex 0.19.0).
                if (is_dir($manifest->installPath)
                    && is_dir($manifest->installPath . '/resources/boost/skills')
                    && $this->installPathStillHostsPackage($manifest->installPath, $manifest->package)) {
                    continue;
                }
            }

            $reap = $reaper->reap($home, SyncEngine::userScopeSlugRootsForSlug($slug), $manifest, [], $checkOnly);

            if (! $checkOnly && ! $reap['retained']) {
                @unlink($file);
            }

            if ($reap['writes'] !== []) {
                $results[] = new UserScopeResult(
                    packageName: $manifest->package !== '' ? $manifest->package : $slug,
                    homeRoot: $home,
                    writes: $reap['writes'],
                    errors: [],
                    check: $checkOnly,
                );
            }
        }

        return $results;
    }

    /**
     * Does the package installed at `$installPath` still carry `$package` as its
     * Composer name? Used to tell a present-but-undiscovered package (skip — its
     * own slug is still there) apart from a rename/replace-in-place (reap — a
     * different package now occupies the path, orphaning this manifest's slug).
     *
     * An empty `$package` (pre-0.19 / corrupt manifest) can't be verified, so it
     * is treated as still-present and never reaped on this basis.
     */
    private function installPathStillHostsPackage(string $installPath, string $package): bool
    {
        if ($package === '') {
            return true;
        }

        $composerJson = $installPath . '/composer.json';
        $raw = is_file($composerJson) ? @file_get_contents($composerJson) : false;
        if ($raw === false) {
            return false;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($decoded) && ($decoded['name'] ?? null) === $package;
    }
}
