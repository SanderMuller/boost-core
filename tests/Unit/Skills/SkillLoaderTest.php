<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;

function loader(): SkillLoader
{
    return new SkillLoader(new FrontmatterParser());
}

/**
 * @return list<Skill>
 */
function skillsFromFixture(string $subdir, ?string $vendor = null): array
{
    /** @var list<Skill> $skills */
    $skills = [];
    foreach (loader()->load(__DIR__ . '/../../Fixtures/skills/' . $subdir, $vendor) as $skill) {
        $skills[] = $skill;
    }

    return $skills;
}

/**
 * @param  list<Skill>  $skills
 */
function findLoadedSkill(array $skills, string $name): Skill
{
    foreach ($skills as $skill) {
        if ($skill->name === $name) {
            return $skill;
        }
    }

    throw new RuntimeException(sprintf('Skill "%s" not loaded.', $name));
}

it('loads skills with frontmatter from a directory', function (): void {
    $skills = skillsFromFixture('host');

    $names = array_map(fn (Skill $s): string => $s->name, $skills);
    expect($names)->toContain('host-skill');
    expect($names)->toContain('shared-name');
});

it('marks loaded skills with the source vendor', function (): void {
    $hostSkills = skillsFromFixture('host', null);
    $vendorSkills = skillsFromFixture('vendor-a', 'test/vendor-a');

    expect($hostSkills[0]->sourceVendor)->toBeNull();
    expect($hostSkills[0]->isHostAuthored())->toBeTrue();

    expect($vendorSkills[0]->sourceVendor)->toBe('test/vendor-a');
    expect($vendorSkills[0]->isHostAuthored())->toBeFalse();
});

it('derives name from filename when frontmatter omits it', function (): void {
    $skills = skillsFromFixture('no-frontmatter');

    expect($skills)->toHaveCount(1);
    expect($skills[0]->name)->toBe('raw');
    expect($skills[0]->frontmatter)->toBe([]);
    expect($skills[0]->body)->toContain('Just a body');
});

it('returns empty iterable for missing directories', function (): void {
    $skills = iterator_to_array(loader()->load('/nonexistent/path/' . bin2hex(random_bytes(4))));

    expect($skills)->toBeEmpty();
});

it('exposes description from frontmatter', function (): void {
    $skills = skillsFromFixture('host');
    $hostSkill = findLoadedSkill($skills, 'host-skill');

    expect($hostSkill->description)->toBe('A skill authored in the host project.');
});
