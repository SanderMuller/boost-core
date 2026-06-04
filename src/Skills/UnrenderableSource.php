<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * One source file a loader will DROP because no registered renderer claims its
 * extension: its relative path PLUS the operator-facing skip message.
 *
 * Carrying the path — not just the message — lets callers classify on the
 * actual source EXTENSION. The skip message embeds advisory example text
 * (``a BladeRenderer for `.blade.php` ``), so a naive `str_contains($message,
 * '.blade.php')` matches EVERY skip, not just the Blade ones. {@see hasExtension}
 * keys off the real filename instead.
 *
 * @internal
 */
final readonly class UnrenderableSource
{
    public function __construct(
        public string $relativePath,
        public string $message,
    ) {}

    public function hasExtension(string $extension): bool
    {
        return str_ends_with(strtolower($this->relativePath), strtolower($extension));
    }
}
