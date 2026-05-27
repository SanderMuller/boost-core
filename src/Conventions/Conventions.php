<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * PHP API entry point for vendor skills (and future internal callers) needing
 * access to boost-core's managed-paths registry. CLI surface is `boost paths`
 * (PathsCommand); this is the programmatic equivalent. See spec §3.10.
 */
final readonly class Conventions
{
    public function __construct(
        private ManagedPathsResolver $resolver,
    ) {}

    public static function default(): self
    {
        return new self(ManagedPathsResolver::default());
    }

    /**
     * @return list<string>  glob patterns boost-core manages for the active agent set
     */
    public function managedPaths(BoostConfig $config): array
    {
        return $this->resolver->patterns($config);
    }
}
