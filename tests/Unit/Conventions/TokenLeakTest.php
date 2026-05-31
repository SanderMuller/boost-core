<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\LeakHit;
use SanderMuller\BoostCore\Conventions\TokenLeak;

it('exposes a file:line location string', function (): void {
    $leak = new TokenLeak('CLAUDE.md', 12, LeakHit::KIND_PROSE_TOKEN, 'mcp.jira', 'inline', 'cause');

    expect($leak->location())->toBe('CLAUDE.md:12');
});

it('serialises to the machine-readable array shape', function (): void {
    $leak = new TokenLeak('.claude/skills/pr/SKILL.md', 4, LeakHit::KIND_FENCE_OPENER, null, null, 'surviving fence');

    expect($leak->toArray())->toBe([
        'file' => '.claude/skills/pr/SKILL.md',
        'line' => 4,
        'kind' => LeakHit::KIND_FENCE_OPENER,
        'path' => null,
        'mode' => null,
        'cause' => 'surviving fence',
    ]);
});
