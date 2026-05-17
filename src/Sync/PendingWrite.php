<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * A file that an AgentTarget wants written, expressed in terms relative to
 * the project root. FileWriter resolves to an absolute path and performs
 * the write (or compares for --check mode).
 */
final readonly class PendingWrite
{
    public function __construct(
        public string $relativePath,
        public string $content,
    ) {}
}
