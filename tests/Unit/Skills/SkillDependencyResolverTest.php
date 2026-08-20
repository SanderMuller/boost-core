<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\CollidingSkillsException;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillDependencyResolver;
use SanderMuller\BoostCore\Sync\SkillSourceCollisionException;

/**
 * @param  list<string>  $requires
 * @param  list<string>  $tags
 */
function depSkill(string $name, array $requires = [], ?string $vendor = 'acme/pack', array $tags = [], bool $requiresValid = true): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/src/' . $name,
        sourceVendor: $vendor,
        tags: $tags,
        requires: $requires,
        requiresValid: $requiresValid,
    );
}

/**
 * @param  list<Skill>  $tagMismatch
 * @param  list<Skill>  $excluded
 * @return array{tagMismatch: list<Skill>, excluded: list<Skill>}
 */
function drops(array $tagMismatch = [], array $excluded = []): array
{
    return ['tagMismatch' => $tagMismatch, 'excluded' => $excluded];
}

/**
 * @param  list<Skill>  $skills
 * @return list<string>
 */
function skillNames(array $skills): array
{
    return array_values(array_map(static fn (Skill $s): string => $s->name, $skills));
}

it('is inert when no shipped skill declares requires', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('alpha'), depSkill('beta')],
        ['acme/pack' => drops(tagMismatch: [depSkill('hidden', tags: ['jira'])])],
    );

    expect(skillNames($result['skills']))->toBe(['alpha', 'beta'])
        ->and($result['pulls'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty()
        ->and($result['malformedRequires'])
        ->toBeEmpty();
});

it('rescues a tag-dropped dependency and reports the pull', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['hidden'])],
        ['acme/pack' => drops(tagMismatch: [depSkill('hidden', tags: ['jira'])])],
    );

    expect(skillNames($result['skills']))->toBe(['dependent', 'hidden'])
        ->and($result['pulls'])->toBe([['name' => 'hidden', 'requiredBy' => 'dependent', 'vendor' => 'acme/pack']])
        ->and($result['warnings'])
        ->toBeEmpty();
});

it('rescues transitively — a rescued skill pulls its own requires', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('a', requires: ['b'])],
        ['acme/pack' => drops(tagMismatch: [
            depSkill('b', requires: ['c'], tags: ['jira']),
            depSkill('c', tags: ['jira']),
        ])],
    );

    expect(skillNames($result['skills']))->toBe(['a', 'b', 'c'])
        ->and(array_column($result['pulls'], 'requiredBy'))->toBe(['a', 'b']);
});

it('terminates on a dependency cycle — both sides co-ship once', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('a', requires: ['b'])],
        ['acme/pack' => drops(tagMismatch: [depSkill('b', requires: ['a'], tags: ['jira'])])],
    );

    expect(skillNames($result['skills']))->toBe(['a', 'b'])
        ->and($result['pulls'])->toHaveCount(1);
});

it('treats a self-require as trivially satisfied', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('a', requires: ['a'])],
        [],
    );

    expect(skillNames($result['skills']))->toBe(['a'])
        ->and($result['pulls'])->toBeEmpty()
        ->and($result['warnings'])
        ->toBeEmpty();
});

it('is satisfied by an already-shipped skill of the demanded name', function (): void {
    // Host shadow or kept vendor skill — either way the name ships already.
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper']), depSkill('helper', vendor: null)],
        ['acme/pack' => drops(tagMismatch: [depSkill('helper', tags: ['jira'])])],
    );

    expect(skillNames($result['skills']))->toBe(['dependent', 'helper'])
        ->and($result['pulls'])->toBeEmpty();
});

it('warns missing when no source holds the demanded name', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['ghost'])],
        [],
    );

    expect(skillNames($result['skills']))->toBe(['dependent'])
        ->and($result['warnings'])->toBe([
            ['name' => 'ghost', 'dependents' => ['dependent'], 'reason' => 'missing'],
        ]);
});

it('aggregates one warning per unsatisfiable name, dependents in first-demanded order', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('first', requires: ['ghost']), depSkill('second', requires: ['ghost'])],
        [],
    );

    expect($result['warnings'])->toBe([
        ['name' => 'ghost', 'dependents' => ['first', 'second'], 'reason' => 'missing'],
    ]);
});

it('falls back to another provider when one provider is excluded', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper'])],
        [
            'acme/first' => drops(excluded: [depSkill('helper', vendor: 'acme/first')]),
            'acme/second' => drops(tagMismatch: [depSkill('helper', vendor: 'acme/second', tags: ['jira'])]),
        ],
    );

    expect(skillNames($result['skills']))->toBe(['dependent', 'helper'])
        ->and($result['pulls'])->toBe([['name' => 'helper', 'requiredBy' => 'dependent', 'vendor' => 'acme/second']])
        ->and($result['warnings'])
        ->toBeEmpty();
});

it('warns excluded when every candidate is blocked by the deny-list', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper'])],
        ['acme/pack' => drops(excluded: [depSkill('helper')])],
    );

    expect(skillNames($result['skills']))->toBe(['dependent'])
        ->and($result['warnings'])->toBe([
            ['name' => 'helper', 'dependents' => ['dependent'], 'reason' => 'excluded'],
        ]);
});

it('throws on a two-provider candidate collision without force', function (): void {
    (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper'])],
        [
            'acme/first' => drops(tagMismatch: [depSkill('helper', vendor: 'acme/first', tags: ['jira'])]),
            'acme/second' => drops(tagMismatch: [depSkill('helper', vendor: 'acme/second', tags: ['jira'])]),
        ],
    );
})->throws(CollidingSkillsException::class);

it('force resolves a two-provider collision to the first provider in precedence order', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper'])],
        [
            'acme/first' => drops(tagMismatch: [depSkill('helper', vendor: 'acme/first', tags: ['jira'])]),
            'acme/second' => drops(tagMismatch: [depSkill('helper', vendor: 'acme/second', tags: ['jira'])]),
        ],
        force: true,
    );

    expect($result['pulls'])->toBe([['name' => 'helper', 'requiredBy' => 'dependent', 'vendor' => 'acme/first']]);
});

it('throws when one provider holds duplicate retained candidates of a demanded name — even under force', function (): void {
    (new SkillDependencyResolver())->resolve(
        [depSkill('dependent', requires: ['helper'])],
        ['acme/pack' => drops(tagMismatch: [
            depSkill('helper', tags: ['jira']),
            depSkill('helper', tags: ['github']),
        ])],
        force: true,
    );
})->throws(SkillSourceCollisionException::class);

it('ignores a never-demanded duplicate in the retained pool', function (): void {
    // Pre-rescue, tag-dropped duplicates were invisible; a duplicate nobody
    // demands must stay that way rather than break a working sync.
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('alpha')],
        ['acme/pack' => drops(tagMismatch: [
            depSkill('dupe', tags: ['jira']),
            depSkill('dupe', tags: ['github']),
        ])],
    );

    expect(skillNames($result['skills']))->toBe(['alpha']);
});

it('reports shipped and rescued skills with malformed boost-requires', function (): void {
    $result = (new SkillDependencyResolver())->resolve(
        [depSkill('broken', requires: [], requiresValid: false), depSkill('dependent', requires: ['hidden'])],
        ['acme/pack' => drops(tagMismatch: [depSkill('hidden', tags: ['jira'], requiresValid: false)])],
    );

    expect($result['malformedRequires'])->toBe(['broken', 'hidden']);
});
