<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
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

it('carries every resolved config field across a renderer merge', function (): void {
    $merger = new InjectedVendorMerger(new SkillTagFilter(), new GuidelineTagFilter());

    // Every field is set to a non-default value on purpose: a rebuild that
    // drops a field falls back to its constructor default, so a field left at
    // that default in the fixture would compare equal and hide the bug.
    $config = new BoostConfig(
        agents: [Agent::CLAUDE_CODE],
        allowedVendors: ['acme/pkg'],
        skillsPath: '/project/.ai/skills',
        guidelinesPath: '/project/.ai/guidelines',
        commandsPath: '/project/.ai/commands',
        disabledEmitters: ['Acme\\Emitters\\Noop'],
        manageGitignore: false,
        tags: ['php'],
        excludedSkills: ['acme/pkg:excluded-skill'],
        excludedGuidelines: ['acme/pkg:excluded-guideline'],
        remoteSkills: [RemoteSkillSource::githubBundle('acme/skills', 'v1.0.0', ['remote'])],
        skillRenderers: [new PassthroughRenderer()],
        conventions: ['php_version' => '8.3'],
        subagentsPath: '/project/.ai/subagents',
    );

    $extra = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $raw;
        }
    };

    $merged = $merger->mergeExtraRenderers($config, [$extra]);

    $defaults = [];
    foreach ((new ReflectionClass(BoostConfig::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->isDefaultValueAvailable()) {
            $defaults[$parameter->getName()] = $parameter->getDefaultValue();
        }
    }

    // Reflection, not a field list, so the guard also covers fields that do
    // not exist yet — the bug was a rebuild that forgot an appended field.
    foreach ((new ReflectionClass(BoostConfig::class))->getProperties() as $property) {
        $name = $property->getName();

        if ($name === 'skillRenderers') {
            continue;
        }

        // Fails loudly when a new field is appended and the fixture above is
        // not extended, instead of passing on a default-equals-default match.
        expect($property->getValue($config))->not->toEqual($defaults[$name] ?? null);
        expect($property->getValue($merged))->toEqual($property->getValue($config));
    }

    expect($merged->subagentsPath)->toBe('/project/.ai/subagents')
        ->and($merged->skillRenderers)->toHaveCount(count($config->skillRenderers) + 1);
});
