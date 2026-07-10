<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillTagFilter;
use SanderMuller\BoostCore\Sync\FilteredSkillPruner;

/**
 * @param  list<string>  $tags
 */
function tagSkill(string $name, array $tags = [], bool $tagsValid = true, ?string $vendor = 'acme/pack'): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/src/' . $name,
        sourceVendor: $vendor,
        tags: $tags,
        tagsValid: $tagsValid,
    );
}

/**
 * @param  list<Tag|string>  $tags
 * @param  list<string>  $excluded
 */
function tagConfig(array $tags = [], array $excluded = []): BoostConfig
{
    return BoostConfig::configure()
        ->withTags($tags)
        ->withExcludedSkills($excluded)
        ->build('/project');
}

it('keeps an untagged skill regardless of consumer tags', function (): void {
    $result = (new SkillTagFilter())->filter([tagSkill('alpha')], tagConfig([Tag::Jira]));

    expect($result['kept'])->toHaveCount(1)
        ->and($result['droppedNames'])
        ->toBeEmpty();
});

it('keeps a skill whose tags are a subset of the consumer tags', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('alpha', ['php'])],
        tagConfig([Tag::Php, Tag::Jira]),
    );

    expect($result['kept'])->toHaveCount(1)
        ->and($result['droppedNames'])
        ->toBeEmpty();
});

it('drops a skill with a tag the consumer did not declare', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('alpha', ['php', 'jira'])],
        tagConfig([Tag::Php]),
    );

    expect($result['kept'])
        ->toBeEmpty()
        ->and($result['droppedNames'])->toBe(['alpha']);
});

it('drops a tag-invalid skill unconditionally — fail closed', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('alpha', [], tagsValid: false)],
        tagConfig([Tag::Php, Tag::Jira]),
    );

    expect($result['kept'])
        ->toBeEmpty()
        ->and($result['droppedNames'])->toBe(['alpha']);
});

it('drops a skill named in the exclude deny-list', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('deploy', vendor: 'acme/pack')],
        tagConfig(excluded: ['acme/pack:deploy']),
    );

    expect($result['kept'])
        ->toBeEmpty()
        ->and($result['droppedNames'])->toBe(['deploy']);
});

it('exclude is vendor-scoped — a same-named skill from another vendor is kept', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('deploy', vendor: 'other/pack')],
        tagConfig(excluded: ['acme/pack:deploy']),
    );

    expect($result['kept'])->toHaveCount(1);
});

it('with no consumer tags, drops every tagged skill but keeps untagged ones', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('tagged', ['jira']), tagSkill('plain')],
        tagConfig(),
    );

    expect($result['kept'])->toHaveCount(1)
        ->and($result['kept'][0]->name)->toBe('plain')
        ->and($result['droppedNames'])->toBe(['tagged']);
});

it('retains a tag-mismatch drop as a rescue-eligible candidate', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('alpha', ['jira'])],
        tagConfig([Tag::Php]),
    );

    expect($result['tagMismatchDrops'])->toHaveCount(1)
        ->and($result['tagMismatchDrops'][0]->name)->toBe('alpha')
        ->and($result['excludedDrops'])
        ->toBeEmpty();
});

it('retains an excluded drop separately — never as a rescue candidate', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('deploy', vendor: 'acme/pack')],
        tagConfig(excluded: ['acme/pack:deploy']),
    );

    expect($result['excludedDrops'])->toHaveCount(1)
        ->and($result['excludedDrops'][0]->name)->toBe('deploy')
        ->and($result['tagMismatchDrops'])
        ->toBeEmpty();
});

it('retains a malformed-tags drop in neither group — fail closed stands', function (): void {
    $result = (new SkillTagFilter())->filter(
        [tagSkill('alpha', [], tagsValid: false)],
        tagConfig([Tag::Php]),
    );

    expect($result['droppedNames'])->toBe(['alpha'])
        ->and($result['tagMismatchDrops'])->toBeEmpty()
        ->and($result['excludedDrops'])
        ->toBeEmpty();
});

it('classifies mixed drops into their own groups in input order', function (): void {
    $result = (new SkillTagFilter())->filter(
        [
            tagSkill('kept'),
            tagSkill('mismatch-a', ['jira']),
            tagSkill('excluded', vendor: 'acme/pack'),
            tagSkill('broken', [], tagsValid: false),
            tagSkill('mismatch-b', ['github']),
        ],
        tagConfig([Tag::Php], excluded: ['acme/pack:excluded']),
    );

    expect(array_map(static fn (Skill $s): string => $s->name, $result['tagMismatchDrops']))->toBe(['mismatch-a', 'mismatch-b'])
        ->and(array_map(static fn (Skill $s): string => $s->name, $result['excludedDrops']))->toBe(['excluded'])
        ->and($result['kept'][0]->name)->toBe('kept')
        ->and($result['droppedByTag'])->toBe(2);
});

it('candidates() excludes a dropped name still claimed by a resolved skill', function (): void {
    $pruner = new FilteredSkillPruner();

    $candidates = $pruner->candidates(
        [tagSkill('shared'), tagSkill('survivor')],
        ['shared', 'gone'],
    );

    // `shared` is re-occupied by a resolved skill → not a prune candidate.
    expect($candidates)->toBe(['gone']);
});
