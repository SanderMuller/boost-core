<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Sync\ClaudeInstructionShadowCheck;
use SanderMuller\BoostCore\Sync\SyncResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The `boost doctor` reporter for a `CLAUDE.md` / `CLAUDE.local.md` that makes
 * Claude Code skip `AGENTS.md`, boost's Claude guidance file. Quiet when there
 * is nothing to say.
 *
 * @internal
 */
final readonly class ClaudeInstructionReporter
{
    /**
     * Reads the warning from the drift run, so a boost-owned `CLAUDE.md` the
     * next sync reaps is not reported.
     */
    public function report(SymfonyStyle $io, ?SyncResult $driftResult): void
    {
        $diagnostics = array_filter(
            $driftResult->diagnostics ?? [],
            ClaudeInstructionShadowCheck::isShadowDiagnostic(...),
        );
        if ($diagnostics === []) {
            return;
        }

        $io->section('Claude Code instructions');
        foreach ($diagnostics as $diagnostic) {
            $io->warning($diagnostic->message);
        }
    }
}
