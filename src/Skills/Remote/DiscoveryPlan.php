<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * What a discovery run is about to do, decided from cheap API calls only.
 *
 * Exists so the caller can tell the operator how many downloads are coming —
 * and ask for confirmation — BEFORE {@see RemoteSkillDiscoverer::discover()}
 * spends them. In bundle mode `$assets` is the exact download list; in path
 * mode a single tarball covers the whole repo, so `$assets` is empty and
 * {@see downloadCount()} is 1.
 *
 * @internal
 */
final readonly class DiscoveryPlan
{
    /**
     * @param  list<array{name: string, asset: string}>  $assets  bundle mode only
     */
    public function __construct(
        public string $mode,
        public ResolvedRef $ref,
        public array $assets = [],
    ) {}

    public function isBundle(): bool
    {
        return $this->mode === RemoteSkillSource::MODE_BUNDLE;
    }

    public function downloadCount(): int
    {
        return $this->isBundle() ? count($this->assets) : 1;
    }
}
