<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * One file emitted by {@see FileEmitter::emit()} (which returns an
 * `iterable<EmittedFile>` — zero, one, or many).
 *
 * Two fields. No content type, no permissions. The contract deliberately
 * stays minimal until a real second consumer demands more — any added field
 * appends with a default, so growth stays non-breaking.
 *
 * @api Stable as of 1.0.
 */
final readonly class EmittedFile
{
    public function __construct(
        public string $relativePath,
        public string $content,
    ) {}
}
