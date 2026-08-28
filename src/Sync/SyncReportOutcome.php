<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use Symfony\Component\Console\Command\Command;

/**
 * What {@see SyncReporter::render()} found, and the exit code boost-core's own
 * CLI would return for it.
 *
 * **Why the two are separable.** Rendering and exiting are owned by different
 * parties. The rendering is boost-core's — two entry points describing one
 * `SyncResult` differently is a divergence bug. The exit code is each
 * package's own promise to its users: a wrapper that has already documented
 * `0` for a dry-run with pending changes cannot adopt a stricter code without
 * breaking its own SemVer contract, and should not have to fork the rendering
 * to keep its word.
 *
 * So a caller can render identically and still decide for itself. Read
 * {@see $exitCode} to follow boost-core, or the individual findings to apply
 * its own rule.
 *
 * @api Stable as of 1.8.
 */
final readonly class SyncReportOutcome
{
    public function __construct(
        /** Top-level errors on the result. Fatal in every mode. */
        public bool $hasErrors,
        /** Check mode only: a vendor's required conventions schema-version is unmet. */
        public bool $hasConventionsError,
        /** Check mode only: an unresolved `boost:conv` token reached emitted output. */
        public bool $hasTokenLeak,
        /** Check mode only: at least one file would be written or deleted. */
        public bool $hasDrift,
        /** The code `bin/boost sync` would exit with. */
        public int $exitCode,
    ) {}

    public static function success(): self
    {
        return new self(false, false, false, false, Command::SUCCESS);
    }
}
