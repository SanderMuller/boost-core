<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * The concrete identifier a `version` string resolves to for cache keying.
 *
 * For path mode, `$resolved` is a 40-char Git SHA. For bundle mode, it is
 * the release tag name (releases on GitHub are tag-anchored, not SHA-anchored).
 * Either form is content-addressed and serves as the cache slot key:
 * `<owner>__<repo>/<resolved>/`.
 */
final readonly class ResolvedRef
{
    public function __construct(
        public string $requested,
        public string $resolved,
    ) {}
}
