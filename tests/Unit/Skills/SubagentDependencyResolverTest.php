<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\Subagent;
use SanderMuller\BoostCore\Skills\SubagentDependencyResolver;

/**
 * @param  list<string>  $requiredSubagents
 */
function demandingSkill(string $name, array $requiredSubagents): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/tmp/' . $name . '.md',
        sourceVendor: null,
        requiredSubagents: $requiredSubagents,
    );
}

function droppedSubagent(string $name, string $vendor): Subagent
{
    return new Subagent(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/tmp/' . $name . '.md',
        sourceVendor: $vendor,
    );
}

it('rescues a tag-dropped subagent a shipping skill requires', function (): void {
    $result = (new SubagentDependencyResolver())->resolve(
        [demandingSkill('evaluate', ['simplification-auditor'])],
        [],
        ['acme/pack' => [
            'tagMismatch' => [droppedSubagent('simplification-auditor', 'acme/pack')],
            'excluded' => [],
        ]],
    );

    expect($result['subagents'])->toHaveCount(1)
        ->and($result['subagents'][0]->name)->toBe('simplification-auditor')
        ->and($result['pulls'])->toBe([[
            'name' => 'simplification-auditor',
            'requiredBy' => 'evaluate',
            'vendor' => 'acme/pack',
        ]])
        ->and($result['warnings'])->toBe([]);
});

it('reports an excluded subagent as excluded, not missing', function (): void {
    // The distinction tells a consumer "you filtered this out" apart from
    // "the package is broken".
    $result = (new SubagentDependencyResolver())->resolve(
        [demandingSkill('evaluate', ['reviewer'])],
        [],
        ['acme/pack' => [
            'tagMismatch' => [],
            'excluded' => [droppedSubagent('reviewer', 'acme/pack')],
        ]],
    );

    expect($result['warnings'])->toBe([[
        'name' => 'reviewer',
        'dependents' => ['evaluate'],
        'reason' => 'excluded',
    ]]);
});

it('reports a name no package provides as missing', function (): void {
    $result = (new SubagentDependencyResolver())->resolve(
        [demandingSkill('evaluate', ['nope'])],
        [],
        [],
    );

    expect($result['warnings'])->toBe([[
        'name' => 'nope',
        'dependents' => ['evaluate'],
        'reason' => 'missing',
    ]]);
});

it('aggregates every dependent of one unsatisfiable name into a single warning', function (): void {
    $result = (new SubagentDependencyResolver())->resolve(
        [
            demandingSkill('evaluate', ['nope']),
            demandingSkill('code-review', ['nope']),
        ],
        [],
        [],
    );

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['dependents'])->toBe(['evaluate', 'code-review']);
});

it('does nothing when the demanded subagent already ships', function (): void {
    $shipping = droppedSubagent('reviewer', 'acme/pack');

    $result = (new SubagentDependencyResolver())->resolve(
        [demandingSkill('evaluate', ['reviewer'])],
        [$shipping],
        [],
    );

    expect($result['subagents'])->toBe([$shipping])
        ->and($result['pulls'])->toBe([])
        ->and($result['warnings'])->toBe([]);
});

it('leaves a subagent nobody requires alone — it is not an orphan', function (): void {
    $shipping = droppedSubagent('standalone', 'acme/pack');

    $result = (new SubagentDependencyResolver())->resolve([], [$shipping], []);

    expect($result['subagents'])->toBe([$shipping])
        ->and($result['warnings'])->toBe([]);
});

it('rescues each demanded name once even when several skills demand it', function (): void {
    $result = (new SubagentDependencyResolver())->resolve(
        [
            demandingSkill('evaluate', ['auditor']),
            demandingSkill('code-review', ['auditor']),
        ],
        [],
        ['acme/pack' => [
            'tagMismatch' => [droppedSubagent('auditor', 'acme/pack')],
            'excluded' => [],
        ]],
    );

    expect($result['subagents'])->toHaveCount(1)
        ->and($result['pulls'])->toHaveCount(1)
        ->and($result['pulls'][0]['requiredBy'])->toBe('evaluate');
});
