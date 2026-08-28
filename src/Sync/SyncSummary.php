<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * The one-line outcome of a sync, and the counts behind it.
 *
 * **This line is already a contract, and was one before it had a name.**
 * `BoostAutoSync::summaryReportsChange()` regex-matches `wrote=<n>,
 * unchanged=<n>, deleted=<n>` out of the binary's output to decide whether a
 * `post-install-cmd` stays silent on a no-op install, and a dedicated test
 * pins the producing side so the two cannot drift. Until now that producer was
 * a private method inside `SyncCommand`, so the coupling was real but
 * unnamed, and a wrapper package driving {@see BoostSync} could not emit the
 * same line without copying the `sprintf()`.
 *
 * Naming it puts one implementation behind both entry points: boost-core's
 * `bin/boost sync` and a wrapper's own command produce identical text, and the
 * Composer hook parses either.
 *
 * The `wrote=`/`unchanged=`/`deleted=` fragment of {@see line()} is frozen —
 * a tool parses it. The surrounding prose is not.
 *
 * @api Stable as of 1.4. Frozen surface: the readonly counts, {@see from()},
 * and {@see line()}'s parseable fragment.
 */
final readonly class SyncSummary
{
    private function __construct(
        public int $wrote,
        public int $unchanged,
        public int $deleted,
        public int $skippedSymlink,
        public int $emittersWrote,
        public int $emittersSkipped,
        public bool $hasEmitters,
    ) {}

    public static function from(SyncResult $result): self
    {
        return new self(
            wrote: $result->countByAction(WriteAction::WROTE),
            unchanged: $result->countByAction(WriteAction::UNCHANGED),
            deleted: $result->countByAction(WriteAction::DELETED),
            skippedSymlink: $result->countByAction(WriteAction::SKIPPED_SYMLINK),
            emittersWrote: $result->countEmittersByAction(EmitterAction::WROTE),
            emittersSkipped: $result->countEmittersByAction(EmitterAction::SKIPPED),
            hasEmitters: $result->emitters !== [],
        );
    }

    /**
     * The success line. In check mode there is nothing written to count, so it
     * reports what did not move instead.
     */
    public function line(bool $checkOnly): string
    {
        $head = $checkOnly
            ? sprintf('No drift. %d file(s) unchanged.', $this->unchanged)
            : sprintf('Sync done. wrote=%d, unchanged=%d, deleted=%d.', $this->wrote, $this->unchanged, $this->deleted);

        return $head . $this->symlinkFragment() . $this->emitterFragment();
    }

    /**
     * Omitted entirely at zero: a `skipped-symlink=0` on every ordinary sync
     * would train operators to ignore the one case that matters.
     */
    private function symlinkFragment(): string
    {
        return $this->skippedSymlink > 0 ? sprintf(' skipped-symlink=%d', $this->skippedSymlink) : '';
    }

    /**
     * Omitted when no emitter ran at all, so a project with no FileEmitter
     * never sees an emitter count it cannot act on.
     */
    private function emitterFragment(): string
    {
        return $this->hasEmitters
            ? sprintf(' emitters(wrote=%d, skipped=%d)', $this->emittersWrote, $this->emittersSkipped)
            : '';
    }
}
