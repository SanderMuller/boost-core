<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Return value of FileEmitter::emit().
 *
 * Two fields. No content type, no permissions. The contract deliberately
 * stays minimal until a real second consumer demands more.
 *
 * @see /Users/sandermuller/Documents/GitHub/elements/internal/boost-file-emitter-contract.md
 */
final readonly class EmittedFile
{
    public function __construct(
        public string $relativePath,
        public string $content,
    ) {}
}
