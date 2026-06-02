<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Wraps `composer/semver` for the spec §6 schema-version handling.
 *
 * Used by:
 * - SyncEngine: per-skill skip-write decision (`satisfies()`)
 * - ConventionsBlockEmitter: scaffold seed (`minRequired()` → `max(...) ?? 1`)
 * - DoctorCommand: mismatch reporting (`satisfies()`)
 *
 * NOT called from `ConventionsSchema::compose()` — composition is
 * version-agnostic; version-based skip-write is a sync-time decision
 * applied per skill (see spec §3.9).
 *
 * @internal
 */
final readonly class VersionMatcher
{
    private VersionParser $parser;

    public function __construct()
    {
        $this->parser = new VersionParser();
    }

    /**
     * True if the host integer schema-version satisfies the vendor's range.
     * Null/empty $required is treated as wildcard `*` (matches any host).
     *
     * Vendor-needs-higher → false (skill is skipped at sync time).
     * Vendor-needs-lower → true (skill applies; warning emitted separately).
     */
    public function satisfies(int $hostVersion, ?string $required): bool
    {
        if ($required === null || trim($required) === '' || trim($required) === '*') {
            return true;
        }

        return Semver::satisfies($this->hostAsSemver($hostVersion), $required);
    }

    /**
     * Returns the lowest integer schema-version the range REQUIRES.
     * `^1` → 1, `^2` → 2, `^1||^2` → 1, `>=3` → 3, `*` or null → null.
     *
     * Used to compute scaffold seed = max(minRequired(...)) ?? 1.
     */
    public function minRequired(?string $required): ?int
    {
        if ($required === null || trim($required) === '' || trim($required) === '*') {
            return null;
        }

        try {
            $constraint = $this->parser->parseConstraints($required);
        } catch (UnexpectedValueException) {
            return null;
        }

        $lowerBound = $constraint->getLowerBound();
        $version = $lowerBound->getVersion();

        if (! str_contains($version, '.')) {
            return null;
        }

        [$major] = explode('.', $version, 2);
        if (! ctype_digit($major)) {
            return null;
        }

        return (int) $major;
    }

    private function hostAsSemver(int $hostVersion): string
    {
        return $hostVersion . '.0.0';
    }
}
