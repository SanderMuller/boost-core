<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\ClaudeInstructionReporter;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\ClaudeInstructionShadowCheck;
use SanderMuller\BoostCore\Sync\SyncResult;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @param  list<Diagnostic>  $diagnostics
 */
function reportClaudeInstructions(?array $diagnostics): string
{
    $output = new BufferedOutput();
    $drift = $diagnostics === null ? null : new SyncResult(writes: [], emitters: [], errors: [], check: true, diagnostics: $diagnostics);

    (new ClaudeInstructionReporter())->report(new SymfonyStyle(new ArrayInput([]), $output), $drift);

    return $output->fetch();
}

it('reports the shadow warning from the drift run', function (): void {
    $display = reportClaudeInstructions([
        Diagnostic::warning(null, ClaudeInstructionShadowCheck::message(['CLAUDE.md'])),
    ]);

    expect($display)->toContain('Claude Code instructions')
        ->toContain('CLAUDE.md')
        ->toContain('@AGENTS.md');
});

it('stays quiet when the drift run has no shadow warning', function (): void {
    expect(reportClaudeInstructions([Diagnostic::warning(null, 'Something else.')]))
        ->toBeEmpty();
});

it('stays quiet when the drift run failed', function (): void {
    expect(reportClaudeInstructions(null))
        ->toBeEmpty();
});
