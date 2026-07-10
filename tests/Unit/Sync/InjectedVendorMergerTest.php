<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillTagFilter;
use SanderMuller\BoostCore\Sync\InjectedVendorMerger;

/**
 * @param  list<string>  $tags
 * @param  list<string>  $requires
 */
function mergerSkill(string $name, string $vendor, array $tags = [], array $requires = []): Skill
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
    );
}

function mergerConfig(): BoostConfig
{
    return BoostConfig::configure()
        ->withTags([Tag::Php])
        ->build('/project');
}

it('retains injected tag-dropped skills per vendor in merge order', function (): void {
    $merger = new InjectedVendorMerger(new SkillTagFilter(), new GuidelineTagFilter());

    $vendorSkills = [];
    $droppedNames = [];
    $tagFilteredCount = 0;
    $retainedDrops = [];

    $merger->mergeSkills(
        [
            'acme/first' => [
                mergerSkill('shipped', 'acme/first', ['php']),
                mergerSkill('hidden-jira', 'acme/first', ['jira']),
            ],
            'acme/second' => [
                mergerSkill('hidden-github', 'acme/second', ['github']),
            ],
        ],
        $vendorSkills,
        $droppedNames,
        $tagFilteredCount,
        mergerConfig(),
        $retainedDrops,
    );

    expect(array_keys($retainedDrops))->toBe(['acme/first', 'acme/second'])
        ->and(array_map(static fn (Skill $s): string => $s->name, $retainedDrops['acme/first']['tagMismatch']))->toBe(['hidden-jira'])
        ->and($retainedDrops['acme/first']['excluded'])->toBeEmpty()
        ->and(array_map(static fn (Skill $s): string => $s->name, $retainedDrops['acme/second']['tagMismatch']))->toBe(['hidden-github'])
        ->and($vendorSkills['acme/first'][0]->name)->toBe('shipped')
        ->and($droppedNames)->toBe(['hidden-jira', 'hidden-github'])
        ->and($tagFilteredCount)->toBe(2);
});

it('omits vendors with nothing retained from the retained-drops map', function (): void {
    $merger = new InjectedVendorMerger(new SkillTagFilter(), new GuidelineTagFilter());

    $vendorSkills = [];
    $droppedNames = [];
    $tagFilteredCount = 0;
    $retainedDrops = [];

    $merger->mergeSkills(
        ['acme/clean' => [mergerSkill('shipped', 'acme/clean', ['php'])]],
        $vendorSkills,
        $droppedNames,
        $tagFilteredCount,
        mergerConfig(),
        $retainedDrops,
    );

    expect($retainedDrops)->toBeEmpty();
});
