<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Read-only context handed to FileEmitter implementations.
 *
 * Three fields, deliberately minimal — per the FileEmitter contract's
 * "we don't know what emitter #2 will need yet" stance. The shape only
 * grows when a real second consumer demands it.
 *
 * @api Stable as of 1.0 — the read-only context passed to {@see FileEmitter}.
 */
final readonly class SyncContext
{
    public function __construct(
        public string $projectRoot,
        public InstalledPackages $packages,
        public BoostConfig $config,
    ) {}
}
