<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\PendingWrite;

/**
 * @param  array<string, mixed>  $frontmatter
 */
function makeSkill(string $name, array $frontmatter, string $body): Skill
{
    return new Skill(
        name: $name,
        description: null,
        frontmatter: $frontmatter,
        body: $body,
        sourcePath: '/fake/'.$name.'.md',
        sourceVendor: null,
    );
}

function makeGuideline(string $name, string $body): Guideline
{
    return new Guideline(
        name: $name,
        description: null,
        frontmatter: [],
        body: $body,
        sourcePath: '/fake/'.$name.'.md',
        sourceVendor: null,
    );
}

it('reports the Claude Code agent and conventional paths', function (): void {
    $target = new ClaudeCodeTarget;

    expect($target->agent())->toBe(Agent::CLAUDE_CODE);
    expect($target->skillsDirectoryRelative())->toBe('.claude/skills');
    expect($target->guidelinesFileRelative())->toBe('CLAUDE.md');
});

it('plans one PendingWrite per skill, named `{name}.md` under skills dir', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [
            makeSkill('foo', ['name' => 'foo'], '# Foo'),
            makeSkill('bar', ['name' => 'bar'], '# Bar'),
        ],
        guidelines: [],
    );

    expect($writes)->toHaveCount(2);
    expect($writes[0])->toBeInstanceOf(PendingWrite::class);
    expect($writes[0]->relativePath)->toBe('.claude/skills/foo.md');
    expect($writes[1]->relativePath)->toBe('.claude/skills/bar.md');
});

it('embeds frontmatter at the top of each skill file', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [makeSkill('foo', ['name' => 'foo', 'description' => 'A foo skill.'], 'Body content.')],
        guidelines: [],
    );

    expect($writes[0]->content)->toContain('---');
    expect($writes[0]->content)->toContain('name: foo');
    expect($writes[0]->content)->toContain('description:');
    expect($writes[0]->content)->toContain('Body content.');
});

it('omits the frontmatter block when frontmatter is empty', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [makeSkill('foo', [], 'Just body.')],
        guidelines: [],
    );

    expect($writes[0]->content)->toBe('Just body.');
});

it('plans a guidelines write at CLAUDE.md when guidelines are present', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [],
        guidelines: [
            makeGuideline('conventions', '# Conventions\n\nFoo.'),
            makeGuideline('style', '# Style\n\nBar.'),
        ],
    );

    expect($writes)->toHaveCount(1);
    expect($writes[0]->relativePath)->toBe('CLAUDE.md');
    expect($writes[0]->content)->toContain('# Conventions');
    expect($writes[0]->content)->toContain('# Style');
    expect($writes[0]->content)->toContain('---'); // separator between guidelines
});

it('omits the guidelines write when no guidelines are provided', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [makeSkill('foo', [], 'body')],
        guidelines: [],
    );

    expect($writes)->toHaveCount(1);
    expect($writes[0]->relativePath)->toBe('.claude/skills/foo.md');
});

it('handles both skills and guidelines in a single plan', function (): void {
    $target = new ClaudeCodeTarget;
    $writes = $target->plan(
        skills: [makeSkill('foo', [], 'F')],
        guidelines: [makeGuideline('g', 'G')],
    );

    expect($writes)->toHaveCount(2);
    $paths = array_map(fn (PendingWrite $w): string => $w->relativePath, $writes);
    expect($paths)->toEqual(['.claude/skills/foo.md', 'CLAUDE.md']);
});
