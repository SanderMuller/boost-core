<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Result of a declared-value path lookup (0.15.0). `found` is true when the
 * slot path EXISTS in the declared conventions — even when `value` is falsy
 * (`false` / `null` / `''` / `[]`) — so declared-empty is never confused with
 * missing (spec D2, path-existence not truthiness).
 */
final readonly class DeclaredLookup
{
    public function __construct(
        public bool $found,
        public mixed $value,
    ) {}
}
