<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\EmitterResult;
use SanderMuller\BoostCore\Sync\SyncReporter;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\SyncSummary;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @param  list<WrittenFile>  $writes
 * @param  list<EmitterResult>  $emitters
 */
function summaryResult(array $writes, array $emitters = []): SyncResult
{
    return new SyncResult(writes: $writes, emitters: $emitters, errors: [], check: false);
}

it('renders the summary line BoostAutoSync parses', function (): void {
    // `BoostAutoSync::summaryReportsChange()` regex-matches this line to decide
    // whether a post-install stays silent. It was produced by a private method
    // and consumed by a regex — a contract in everything but name. Naming it
    // makes both sides point at one place.
    $summary = SyncSummary::from(summaryResult([
        new WrittenFile('a.md', '/tmp/a.md', WriteAction::WROTE),
        new WrittenFile('b.md', '/tmp/b.md', WriteAction::UNCHANGED),
        new WrittenFile('c.md', '/tmp/c.md', WriteAction::UNCHANGED),
        new WrittenFile('d.md', '/tmp/d.md', WriteAction::DELETED),
    ]));

    expect($summary->wrote)->toBe(1)
        ->and($summary->unchanged)->toBe(2)
        ->and($summary->deleted)->toBe(1)
        ->and($summary->line(checkOnly: false))->toBe('Sync done. wrote=1, unchanged=2, deleted=1.')
        ->and($summary->line(checkOnly: false))->toMatch('/wrote=\d+, unchanged=\d+, deleted=\d+/');
});

it('renders the check-mode line without the write counts', function (): void {
    $summary = SyncSummary::from(summaryResult([new WrittenFile('a.md', '/tmp/a.md', WriteAction::UNCHANGED)]));

    expect($summary->line(checkOnly: true))->toBe('No drift. 1 file(s) unchanged.');
});

it('appends the symlink and emitter fragments only when they apply', function (): void {
    $summary = SyncSummary::from(summaryResult(
        [
            new WrittenFile('a.md', '/tmp/a.md', WriteAction::WROTE),
            new WrittenFile('b.md', '/tmp/b.md', WriteAction::SKIPPED_SYMLINK),
        ],
        [new EmitterResult('Acme\Emitter', 'acme/pkg', EmitterAction::WROTE, '.mcp.json', null)],
    ));

    expect($summary->line(checkOnly: false))
        ->toBe('Sync done. wrote=1, unchanged=0, deleted=0. skipped-symlink=1 emitters(wrote=1, skipped=0)');
});

it('reports findings separately from the exit code it would use', function (): void {
    // A wrapper that has already documented exit 0 for its dry-run cannot adopt
    // a stricter code without breaking its own SemVer promise. It should not
    // have to fork the rendering to keep its word, so render() hands back what
    // it FOUND alongside the code boost-core would return.
    $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

    $drifted = new SyncResult(
        writes: [new WrittenFile('a.md', '/tmp/a.md', WriteAction::WOULD_WRITE)],
        emitters: [],
        errors: [],
        check: true,
    );

    $outcome = (new SyncReporter())->render($io, $drifted, checkOnly: true, projectRoot: sys_get_temp_dir());

    expect($outcome->hasDrift)->toBeTrue()
        ->and($outcome->hasErrors)->toBeFalse()
        ->and($outcome->exitCode)->toBe(1);
});

it('reports a clean run as success with no findings', function (): void {
    $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

    $clean = new SyncResult(
        writes: [new WrittenFile('a.md', '/tmp/a.md', WriteAction::UNCHANGED)],
        emitters: [],
        errors: [],
        check: true,
    );

    $outcome = (new SyncReporter())->render($io, $clean, checkOnly: true, projectRoot: sys_get_temp_dir());

    expect($outcome->hasDrift)->toBeFalse()
        ->and($outcome->exitCode)->toBe(0);
});
