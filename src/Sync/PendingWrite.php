<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Conventions\ManagedRegion;

/**
 * A file that an AgentTarget wants written, expressed in terms relative to
 * the project root. FileWriter resolves to an absolute path and performs
 * the write (or compares for --check mode).
 *
 * When `$managedRegion` is non-null, `$content` is treated as the body to
 * place BETWEEN the markers. FileWriter reads the existing file, applies
 * `ManagedRegion::render()`, and writes the merged result — preserving any
 * operator-authored content OUTSIDE the markers. This is how `CLAUDE.md`,
 * `AGENTS.md`, etc. coexist with operator-added sections like the Project
 * Conventions block (boost-core 0.8.2+).
 */
final readonly class PendingWrite
{
    public function __construct(
        public string $relativePath,
        public string $content,
        public ?ManagedRegion $managedRegion = null,
    ) {}
}
