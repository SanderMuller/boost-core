<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * Result of {@see RemoteSkillCache::ensureCached()} — the local cache slot
 * directory and the concrete identifier the source resolved to. Callers
 * (notably `SyncEngine`'s remote-source ingest) compose per-skill paths via
 * `$cached->skillPath($ref)`.
 *
 * @internal
 */
final readonly class CachedSource
{
    public function __construct(
        public string $slotDir,
        public string $resolvedRef,
    ) {}

    public function skillPath(RemoteSkillRef $ref): string
    {
        return $this->slotDir . '/' . $ref->name;
    }
}
