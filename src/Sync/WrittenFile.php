<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * @internal
 */
final readonly class WrittenFile
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public WriteAction $action,
    ) {}
}
