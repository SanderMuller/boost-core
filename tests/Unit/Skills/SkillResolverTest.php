<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Skills\CollidingSkillsException;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillResolver;

function fixtureSkillLoader(): SkillLoader
{
    return new SkillLoader(new FrontmatterParser);
}

/**
 * @return list<Skill>
 */
function loadFixture(string $subdir, ?string $vendor = null): array
{
    /** @var list<Skill> $skills */
    $skills = [];
    foreach (fixtureSkillLoader()->load(__DIR__.'/../../Fixtures/skills/'.$subdir, $vendor) as $skill) {
        $skills[] = $skill;
    }

    return $skills;
}

/**
 * @param  list<Skill>  $skills
 */
function findSkill(array $skills, string $name): Skill
{
    foreach ($skills as $skill) {
        if ($skill->name === $name) {
            return $skill;
        }
    }

    throw new RuntimeException(sprintf('Skill "%s" not in set.', $name));
}

it('returns host skills when no vendors are provided', function (): void {
    $resolver = new SkillResolver;
    $resolved = $resolver->resolve(loadFixture('host', null), []);

    $names = array_map(fn (Skill $s): string => $s->name, $resolved);
    expect($names)->toEqual(['host-skill', 'shared-name']);
});

it('host always wins over vendor on collision', function (): void {
    $resolver = new SkillResolver;
    $resolved = $resolver->resolve(
        loadFixture('host', null),
        ['test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a')],
    );

    $sharedName = findSkill($resolved, 'shared-name');
    expect($sharedName->isHostAuthored())->toBeTrue();
    expect($sharedName->description)->toBe("Host's version, should win collisions.");
});

it('includes vendor skills that have no host equivalent', function (): void {
    $resolver = new SkillResolver;
    $resolved = $resolver->resolve(
        loadFixture('host', null),
        ['test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a')],
    );

    $vendorASkill = findSkill($resolved, 'vendor-a-skill');
    expect($vendorASkill->sourceVendor)->toBe('test/vendor-a');
});

it('throws on vendor-vs-vendor collision without --force', function (): void {
    $resolver = new SkillResolver;
    $resolver->resolve(
        [],
        [
            'test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a'),
            'test/vendor-b' => loadFixture('vendor-b', 'test/vendor-b'),
        ],
    );
})->throws(CollidingSkillsException::class, 'colliding');

it('captures both vendors in the collision exception', function (): void {
    $resolver = new SkillResolver;

    try {
        $resolver->resolve(
            [],
            [
                'test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a'),
                'test/vendor-b' => loadFixture('vendor-b', 'test/vendor-b'),
            ],
        );
        throw new RuntimeException('Expected CollidingSkillsException');
    } catch (CollidingSkillsException $e) {
        expect($e->name)->toBe('colliding');
        expect($e->vendors)->toEqual(['test/vendor-a', 'test/vendor-b']);
    }
});

it('with --force, vendor declaration order wins silently', function (): void {
    $resolver = new SkillResolver;
    $resolved = $resolver->resolve(
        [],
        [
            'test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a'),
            'test/vendor-b' => loadFixture('vendor-b', 'test/vendor-b'),
        ],
        force: true,
    );

    $colliding = findSkill($resolved, 'colliding');
    expect($colliding->sourceVendor)->toBe('test/vendor-a');
    expect($colliding->description)->toBe("Vendor A's version. Will collide with vendor-b.");
});

it('host overrides ALL vendors silently, even multiple ones', function (): void {
    // Same skill name in host + 2 vendors. Host wins, no collision exception fires
    // because vendor-vs-vendor never compete when host already claimed the name.
    $resolver = new SkillResolver;

    $vendorBSkill = new Skill(
        name: 'shared-name',
        description: 'Vendor B variant',
        frontmatter: [],
        body: '',
        sourcePath: '/fake',
        sourceVendor: 'test/vendor-b',
    );

    $resolved = $resolver->resolve(
        loadFixture('host', null), // has 'shared-name'
        [
            'test/vendor-a' => loadFixture('vendor-a', 'test/vendor-a'), // also 'shared-name'
            'test/vendor-b' => [$vendorBSkill],
        ],
    );

    $sharedName = findSkill($resolved, 'shared-name');
    expect($sharedName->isHostAuthored())->toBeTrue();
});
