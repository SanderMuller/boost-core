<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ManagedPathsResolver;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * @param  list<Agent>  $agents
 */
function makeConfig(array $agents): BoostConfig
{
    return new BoostConfig(
        agents: $agents,
        allowedVendors: [],
        skillsPath: '.ai/skills',
        guidelinesPath: '.ai/guidelines',
        commandsPath: '.ai/commands',
        disabledEmitters: [],
    );
}

it('returns patterns from active agents only', function (): void {
    $resolver = ManagedPathsResolver::default();
    $patterns = $resolver->patterns(makeConfig([Agent::CLAUDE_CODE]));

    expect($patterns)->not->toBeEmpty()->each->toBeString();
});

it('returns empty when no agents are allowlisted', function (): void {
    $resolver = ManagedPathsResolver::default();
    $patterns = $resolver->patterns(makeConfig([]));

    expect($patterns)
        ->toBeEmpty();
});

it('deduplicates patterns from overlapping agent dirs', function (): void {
    $resolver = ManagedPathsResolver::default();
    $patterns = $resolver->patterns(makeConfig([Agent::CLAUDE_CODE, Agent::CURSOR]));

    expect($patterns)->toBe(array_values(array_unique($patterns)));
});
