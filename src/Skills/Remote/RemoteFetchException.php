<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use RuntimeException;
use Throwable;

/**
 * Typed exception for remote-fetch failures.
 *
 * `$reason` is one of the public constants — callers branch on it to decide
 * how to surface the failure (warn + skip, fall back to cache, escalate to
 * abort under `BOOST_REMOTE_STRICT`).
 *
 * @internal
 */
final class RemoteFetchException extends RuntimeException
{
    public const NETWORK_UNREACHABLE = 'network-unreachable';

    public const NOT_FOUND = 'not-found';

    public const SERVER_ERROR = 'server-error';

    public const RATE_LIMITED = 'rate-limited';

    public const UNAUTHORIZED = 'unauthorized';

    public const BAD_REDIRECT = 'bad-redirect';

    public const MALFORMED_RESPONSE = 'malformed-response';

    public function __construct(
        string $message,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
