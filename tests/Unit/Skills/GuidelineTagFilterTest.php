<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;

/**
 * @param  list<string>  $tags
 */
function gtfGuideline(string $name, array $tags = [], bool $tagsValid = true): Guideline
{
    return new Guideline(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/src/' . $name,
        sourceVendor: 'acme/pack',
        tags: $tags,
        tagsValid: $tagsValid,
    );
}

/**
 * @param  list<Tag|string>  $tags
 * @param  list<string>  $excludedGuidelines
 */
function gtfConfig(array $tags = [], array $excludedGuidelines = []): BoostConfig
{
    return BoostConfig::configure()
        ->withTags($tags)
        ->withExcludedGuidelines($excludedGuidelines)
        ->build('/project');
}

it('keeps an untagged guideline', function (): void {
    $kept = (new GuidelineTagFilter())->filter([gtfGuideline('a')], gtfConfig());

    expect($kept)->toHaveCount(1);
});

it('keeps a guideline whose tags are a subset of the project tags', function (): void {
    $kept = (new GuidelineTagFilter())->filter([gtfGuideline('a', ['php'])], gtfConfig([Tag::Php, Tag::Jira]));

    expect($kept)->toHaveCount(1);
});

it('drops a guideline whose tags are not a subset', function (): void {
    $kept = (new GuidelineTagFilter())->filter([gtfGuideline('a', ['php', 'jira'])], gtfConfig([Tag::Php]));

    expect($kept)->toBeEmpty();
});

it('drops a tag-invalid guideline (fails closed)', function (): void {
    $kept = (new GuidelineTagFilter())->filter([gtfGuideline('a', [], tagsValid: false)], gtfConfig([Tag::Php]));

    expect($kept)->toBeEmpty();
});

it('filters a mixed set, keeping only shippable guidelines', function (): void {
    $kept = (new GuidelineTagFilter())->filter([
        gtfGuideline('keep-untagged'),
        gtfGuideline('keep-subset', ['php']),
        gtfGuideline('drop-missing-tag', ['jira']),
        gtfGuideline('drop-invalid', [], tagsValid: false),
    ], gtfConfig([Tag::Php]));

    expect(array_map(static fn (Guideline $g): string => $g->name, $kept))
        ->toBe(['keep-untagged', 'keep-subset']);
});

it('drops a guideline on the withExcludedGuidelines deny-list, even when its tags match', function (): void {
    $kept = (new GuidelineTagFilter())->filter(
        [gtfGuideline('a', ['php'])],
        gtfConfig([Tag::Php], ['acme/pack:a']),
    );

    expect($kept)->toBeEmpty();
});

it('keeps a guideline the deny-list does not name', function (): void {
    $kept = (new GuidelineTagFilter())->filter(
        [gtfGuideline('a')],
        gtfConfig([], ['acme/pack:other']),
    );

    expect($kept)->toHaveCount(1);
});
