<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\MultiPrefixEarly;

/**
 * Test fixture for 0.11.0 multi-prefix discovery — a class named
 * `BoostWrapper` at the FIRST PSR-4 prefix of a multi-prefix package that
 * does NOT implement the contract. Pair with `MultiPrefixLate`'s implementing
 * class at the second prefix to verify the engine prefers the
 * contract-implementing candidate.
 */
final class BoostWrapper {}
