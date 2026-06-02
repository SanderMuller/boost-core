<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillTagDiagnostics;

/**
 * @param  list<string>  $tags
 */
function diagSkill(string $name, array $tags = [], bool $tagsValid = true, ?string $vendor = 'acme/pack'): Skill
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
 * @param  list<string>  $excludedGuidelines
 */
function diagConfig(array $tags = [], array $excluded = [], array $excludedGuidelines = []): BoostConfig
{
    return BoostConfig::configure()
        ->withTags($tags)
        ->withExcludedSkills($excluded)
        ->withExcludedGuidelines($excludedGuidelines)
        ->build('/project');
}

it('reports a tag-eligible skill', function (): void {
    $status = (new SkillTagDiagnostics())->status(diagSkill('a', ['php']), diagConfig([Tag::Php]));

    expect($status)->toBe('tag-eligible');
});

it('reports an untagged skill as tag-eligible', function (): void {
    $status = (new SkillTagDiagnostics())->status(diagSkill('a'), diagConfig());

    expect($status)->toBe('tag-eligible');
});

it('reports a filtered skill with the tags to declare', function (): void {
    $status = (new SkillTagDiagnostics())->status(diagSkill('a', ['php', 'jira']), diagConfig([Tag::Php]));

    expect($status)->toContain('filtered')
        ->and($status)->toContain('jira');
});

it('reports a tag-invalid skill', function (): void {
    $status = (new SkillTagDiagnostics())->status(diagSkill('a', [], tagsValid: false), diagConfig([Tag::Php]));

    expect($status)->toContain('invalid tags');
});

it('reports an excluded skill', function (): void {
    $status = (new SkillTagDiagnostics())->status(
        diagSkill('deploy', vendor: 'acme/pack'),
        diagConfig(excluded: ['acme/pack:deploy']),
    );

    expect($status)->toContain('excluded');
});

it('surfaces declared tags matched by no installed skill', function (): void {
    $unused = (new SkillTagDiagnostics())->declaredButUnusedTags(
        diagConfig([Tag::Php, Tag::Jira]),
        ['php'],
    );

    expect($unused)->toBe(['jira']);
});

it('flags near-duplicate tag pairs by containment', function (): void {
    $pairs = (new SkillTagDiagnostics())->nearDuplicates(['jira', 'jira-cloud', 'php']);

    expect($pairs)->toBe([['jira', 'jira-cloud']]);
});

it('reports no near-duplicates for distinct tags', function (): void {
    $pairs = (new SkillTagDiagnostics())->nearDuplicates(['php', 'jira', 'frontend']);

    expect($pairs)
        ->toBeEmpty();
});

it('groups filtered skills by the tag needed to enable them', function (): void {
    $groups = (new SkillTagDiagnostics())->filteredSkillsByMissingTags(
        [
            diagSkill('jira-triage', ['jira']),
            diagSkill('jira-sync', ['jira']),
            diagSkill('frontend-lint', ['frontend']),
            diagSkill('ships', ['php']),
        ],
        diagConfig([Tag::Php]),
    );

    expect($groups)->toBe([
        ['tags' => ['frontend'], 'skills' => ['acme/pack:frontend-lint']],
        ['tags' => ['jira'], 'skills' => ['acme/pack:jira-sync', 'acme/pack:jira-triage']],
    ]);
});

it('groups a multi-tag skill under its full missing-tag set', function (): void {
    $groups = (new SkillTagDiagnostics())->filteredSkillsByMissingTags(
        [diagSkill('jira-rework', ['jira', 'github'])],
        diagConfig(),
    );

    expect($groups)->toBe([
        ['tags' => ['github', 'jira'], 'skills' => ['acme/pack:jira-rework']],
    ]);
});

it('omits invalid-tag and excluded skills from the enable roll-up', function (): void {
    $groups = (new SkillTagDiagnostics())->filteredSkillsByMissingTags(
        [
            diagSkill('broken', [], tagsValid: false),
            diagSkill('deploy', ['jira']),
        ],
        diagConfig(excluded: ['acme/pack:deploy']),
    );

    expect($groups)->toBeEmpty();
});

it('returns nothing when every tagged skill is already eligible', function (): void {
    $groups = (new SkillTagDiagnostics())->filteredSkillsByMissingTags(
        [diagSkill('a', ['php']), diagSkill('b')],
        diagConfig([Tag::Php]),
    );

    expect($groups)->toBeEmpty();
});

/**
 * @param  list<string>  $tags
 */
function diagGuideline(string $name, array $tags = [], bool $tagsValid = true): Guideline
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

it('reports a guideline with a declared tag as tag-eligible', function (): void {
    $status = (new SkillTagDiagnostics())->guidelineStatus(diagGuideline('g', ['php']), diagConfig([Tag::Php]));

    expect($status)->toBe('tag-eligible');
});

it('reports an untagged guideline as tag-eligible', function (): void {
    $status = (new SkillTagDiagnostics())->guidelineStatus(diagGuideline('g'), diagConfig());

    expect($status)->toBe('tag-eligible');
});

it('reports a filtered guideline with the tags to declare', function (): void {
    $status = (new SkillTagDiagnostics())->guidelineStatus(diagGuideline('g', ['php', 'jira']), diagConfig([Tag::Php]));

    expect($status)->toContain('filtered')
        ->and($status)->toContain('jira');
});

it('reports a tag-invalid guideline', function (): void {
    $status = (new SkillTagDiagnostics())->guidelineStatus(diagGuideline('g', [], tagsValid: false), diagConfig([Tag::Php]));

    expect($status)->toContain('invalid tags');
});

it('reports an excluded guideline', function (): void {
    $status = (new SkillTagDiagnostics())->guidelineStatus(
        diagGuideline('g', ['php']),
        diagConfig([Tag::Php], excludedGuidelines: ['acme/pack:g']),
    );

    expect($status)->toContain('excluded');
});
