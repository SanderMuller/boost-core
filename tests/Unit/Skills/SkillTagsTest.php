<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;

/**
 * Write a single skill file with the given raw frontmatter block, load it,
 * and return the resulting Skill. `$fileName` lets a test exercise both the
 * folder-form `SKILL.md` and a flat `<name>.md` — tags come from frontmatter
 * `metadata.boost-tags`, so both forms can be tagged.
 */
function loadSkillWithFrontmatter(string $frontmatter, string $fileName = 'SKILL.md'): Skill
{
    $base = sys_get_temp_dir() . '/boost-tags-' . bin2hex(random_bytes(6));
    mkdir($base, 0o755, recursive: true);
    file_put_contents($base . '/' . $fileName, "---\n{$frontmatter}\n---\nBody.\n");

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

it('loads no metadata as untagged-valid', function (): void {
    $skill = loadSkillWithFrontmatter('name: alpha');

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('loads metadata without a boost-tags key as untagged-valid', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  other-key: value");

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('loads space-delimited boost-tags', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags: 'php jira'");

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)->toBe(['php', 'jira']);
});

it('normalizes and collapses whitespace in boost-tags', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags: \"  PHP   Jira\tGitHub-Issues  \"");

    expect($skill->tags)->toBe(['php', 'jira', 'github-issues']);
});

it('dedupes boost-tags', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags: 'php Php PHP'");

    expect($skill->tags)->toBe(['php']);
});

it('treats an empty boost-tags string as untagged-valid', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags: ''");

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('treats a whitespace-only boost-tags string as untagged-valid', function (): void {
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags: '   '");

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('fails closed when boost-tags is not a string', function (): void {
    // A YAML list where the spec requires a string → malformed → fail closed.
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags:\n    - php\n    - jira");

    expect($skill->tagsValid)->toBeFalse()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('fails closed when boost-tags is present but blank (YAML null)', function (): void {
    // `boost-tags:` with no value parses to null — the vendor declared the
    // key but gave no string. Fail closed rather than silently untagged.
    $skill = loadSkillWithFrontmatter("name: alpha\nmetadata:\n  boost-tags:");

    expect($skill->tagsValid)->toBeFalse()
        ->and($skill->tags)
        ->toBeEmpty();
});

it('tags a flat <name>.md skill from its frontmatter metadata', function (): void {
    $skill = loadSkillWithFrontmatter("name: flat\nmetadata:\n  boost-tags: 'php'", 'flat.md');

    expect($skill->tagsValid)->toBeTrue()
        ->and($skill->tags)->toBe(['php']);
});
