<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use RuntimeException;
use Throwable;

/**
 * Typed exception for remote-source extraction failures.
 *
 * `$reason` is one of the public constants — callers (the cache layer)
 * branch on it to surface the failure as `malformed` source state and
 * skip + warn.
 */
final class RemoteExtractException extends RuntimeException
{
    public const MALFORMED = 'malformed';

    public const PATH_TRAVERSAL = 'path-traversal';

    public const ABSOLUTE_PATH = 'absolute-path';

    public const SYMLINK = 'symlink';

    public const SIZE_LIMIT = 'size-limit';

    public const ENTRY_COUNT = 'entry-count';

    public const DISK_FULL = 'disk-full';

    public function __construct(
        string $message,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
