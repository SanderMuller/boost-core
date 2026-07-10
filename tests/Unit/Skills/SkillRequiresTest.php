<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;

/**
 * Write a single skill file with the given raw frontmatter block, load it,
 * and return the resulting Skill — the `boost-requires` counterpart of
 * SkillTagsTest's helper.
 */
function loadSkillWithRequiresFrontmatter(string $frontmatter): Skill
{
    $base = sys_get_temp_dir() . '/boost-requires-' . bin2hex(random_bytes(6));
    mkdir($base, 0o755, recursive: true);
    file_put_contents($base . '/SKILL.md', "---\n{$frontmatter}\n---\nBody.\n");

    try {
        /** @var list<Skill> $skills */
        $skills = [];
        foreach ((new SkillLoader(new FrontmatterParser()))->load($base) as $skill) {
            $skills[] = $skill;
        }

        expect($skills)->toHaveCount(1);

        return $skills[0];
    } finally {
        cleanupTestDir($base);
    }
}

it('loads no metadata as no-requires-valid', function (): void {
    $skill = loadSkillWithRequiresFrontmatter('name: alpha');

    expect($skill->requiresValid)->toBeTrue()
        ->and($skill->requires)
        ->toBeEmpty();
});

it('loads space-delimited boost-requires', function (): void {
    $skill = loadSkillWithRequiresFrontmatter("name: alpha\nmetadata:\n  boost-requires: 'write-spec code-review'");

    expect($skill->requiresValid)->toBeTrue()
        ->and($skill->requires)->toBe(['write-spec', 'code-review']);
});

it('loads boost-requires beside boost-tags without interference', function (): void {
    $skill = loadSkillWithRequiresFrontmatter("name: alpha\nmetadata:\n  boost-tags: 'github'\n  boost-requires: 'evaluate'");

    expect($skill->tags)->toBe(['github'])
        ->and($skill->requires)->toBe(['evaluate']);
});

it('marks requires invalid when boost-requires is a YAML list', function (): void {
    $skill = loadSkillWithRequiresFrontmatter("name: alpha\nmetadata:\n  boost-requires:\n    - write-spec");

    expect($skill->requiresValid)->toBeFalse()
        ->and($skill->requires)
        ->toBeEmpty();
});

it('marks requires invalid when boost-requires is present but blank (YAML null)', function (): void {
    $skill = loadSkillWithRequiresFrontmatter("name: alpha\nmetadata:\n  boost-requires:");

    expect($skill->requiresValid)->toBeFalse()
        ->and($skill->requires)
        ->toBeEmpty();
});

it('does not stop a malformed-requires skill from carrying its tags', function (): void {
    // Unlike tagsValid, requiresValid=false must not affect tag shipping
    // semantics — requires gate completeness, not scoping.
    $skill = loadSkillWithRequiresFrontmatter("name: alpha\nmetadata:\n  boost-tags: 'php'\n  boost-requires:\n    - broken");

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)->toBe(['php'])
        ->and($skill->requiresValid)->toBeFalse();
});
