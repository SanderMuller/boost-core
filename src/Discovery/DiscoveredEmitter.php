<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use SanderMuller\BoostCore\Contracts\FileEmitter;

/**
 * @internal
 */
final readonly class DiscoveredEmitter
{
    public function __construct(
        public FileEmitter $emitter,
        public string $vendor,
        public string $fqcn,
    ) {}
}
