<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use RuntimeException;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Env;
use Throwable;

/**
 * Thrown when a {@see SkillRenderer}
 * raises during `render()` and {@see Env::RENDER_STRICT}
 * is set. In lenient mode (default), the loader catches the original
 * `Throwable` and appends the error message to `SyncResult::errors`
 * instead; this exception class is only used to abort the sync transaction.
 */
final class SkillRenderException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
