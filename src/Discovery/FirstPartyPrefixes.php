<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

/**
 * Hardcoded first-party package matcher for the install picker.
 *
 * Packages matching these patterns get pre-checked in the multi-select.
 * Pre-checking is UX only — does NOT bypass the allowlist. Users can
 * uncheck them and they won't be allowlisted.
 *
 * The canonical list:
 * - `sandermuller/boost-*` (boost-core and any sibling foundation packages)
 * - `sandermuller/package-boost-*` (-php, -laravel variants)
 * - `sandermuller/project-boost` (exact, for the app-dev bundle)
 * - `sandermuller/project-boost-*` (future framework adapters)
 *
 * NOT included: `sandermuller/package-boost` (the retired guidance stub).
 *
 * @internal
 */
final class FirstPartyPrefixes
{
    /** @var list<string> */
    private const PREFIXES = [
        'sandermuller/boost-',
        'sandermuller/package-boost-',
        'sandermuller/project-boost-',
    ];

    /** @var list<string> */
    private const EXACT = [
        'sandermuller/project-boost',
    ];

    public function matches(string $packageName): bool
    {
        if (in_array($packageName, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($packageName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return self::PREFIXES;
    }

    /**
     * @return list<string>
     */
    public function exact(): array
    {
        return self::EXACT;
    }
}
