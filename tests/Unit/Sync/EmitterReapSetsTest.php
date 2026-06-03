<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\EmitterResult;
use SanderMuller\BoostCore\Sync\OrphanReaper;

/**
 * 0.21.0 multi-file regression guard: one emitter (FQCN) can now produce several
 * EmitterResults in a run. `emitterReapSets()` must preserve an emitter's prior
 * files only when it is FULLY down (no live output) — a partially-successful
 * emitter is alive, so its dormant files stay reapable.
 */
it('does NOT preserve a multi-file emitter that still produced live output', function (): void {
    $sets = OrphanReaper::emitterReapSets([
        new EmitterResult('Vendor\\Multi', 'vendor/multi', EmitterAction::WROTE, '.multi/a.txt', null),
        new EmitterResult('Vendor\\Multi', 'vendor/multi', EmitterAction::ERRORED, '.multi/b.txt', 'collision'),
    ]);

    expect($sets['preserved'])->not->toHaveKey('Vendor\\Multi') // alive → dormant files reapable
        ->and($sets['intended'])->toHaveKey('.multi/a.txt')
        ->and($sets['hasLiveOutput'])->toBeTrue();
});

it('preserves a fully-down emitter (errored with no live output)', function (): void {
    $sets = OrphanReaper::emitterReapSets([
        new EmitterResult('Vendor\\Down', 'vendor/down', EmitterAction::ERRORED, null, 'boom'),
    ]);

    expect($sets['preserved'])->toHaveKey('Vendor\\Down')
        ->and($sets['hasLiveOutput'])->toBeFalse();
});

it('preserves a disabled emitter', function (): void {
    $sets = OrphanReaper::emitterReapSets([
        new EmitterResult('Vendor\\Off', 'vendor/off', EmitterAction::DISABLED, null, 'disabled'),
    ]);

    expect($sets['preserved'])->toHaveKey('Vendor\\Off');
});
