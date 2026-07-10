<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;

it('withBody replaces only the body and preserves every other field', function (): void {
    $skill = new Skill(
        name: 'alpha',
        description: 'desc',
        frontmatter: ['name' => 'alpha', 'metadata' => ['boost-requires' => 'beta']],
        body: 'original',
        sourcePath: '/src/alpha/SKILL.md',
        sourceVendor: 'acme/pack',
        tags: ['php'],
        tagsValid: true,
        assets: [new SkillAsset('references/notes.md', 'notes')],
        requires: ['beta'],
        requiresValid: false,
    );

    $copy = $skill->withBody('replaced');

    expect($copy->body)->toBe('replaced')
        ->and($copy->name)->toBe($skill->name)
        ->and($copy->description)->toBe($skill->description)
        ->and($copy->frontmatter)->toBe($skill->frontmatter)
        ->and($copy->sourcePath)->toBe($skill->sourcePath)
        ->and($copy->sourceVendor)->toBe($skill->sourceVendor)
        ->and($copy->tags)->toBe($skill->tags)
        ->and($copy->tagsValid)->toBe($skill->tagsValid)
        ->and($copy->assets)->toBe($skill->assets)
        ->and($copy->requires)->toBe($skill->requires)
        ->and($copy->requiresValid)->toBe($skill->requiresValid);
});

it('defaults requires to empty-valid for constructors that predate the fields', function (): void {
    $skill = new Skill(
        name: 'alpha',
        description: null,
        frontmatter: [],
        body: 'b',
        sourcePath: '/s',
        sourceVendor: null,
    );

    expect($skill->requires)->toBeEmpty()
        ->and($skill->requiresValid)->toBeTrue();
});
