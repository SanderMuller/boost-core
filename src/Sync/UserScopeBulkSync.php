<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Discovery\VendorScanner;
use Throwable;

/**
 * Drives `boost sync --scope=user --all`: user-scope sync every installed
 * package that ships `resources/boost/skills/`.
 *
 * Extracted from {@see SyncEngine} so the per-package loop + collision
 * guard do not load the engine's class cognitive-complexity budget. The
 * explicit-command form of what the retired Composer plugin's
 * `runGlobalSync` did automatically on a `composer global` operation.
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

        foreach ($vendorScanner->discover() as $vendor) {
            if ($vendor->skillsPath === null) {
                continue;
            }

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

        return $results;
    }
}
