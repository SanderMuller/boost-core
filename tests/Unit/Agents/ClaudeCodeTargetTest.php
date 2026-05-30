<?php declare(strict_types=1);

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
        sourcePath: '/fake/' . $name . '.md',
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
        sourcePath: '/fake/' . $name . '.md',
        sourceVendor: null,
    );
}

it('reports the Claude Code agent and conventional paths', function (): void {
    $target = new ClaudeCodeTarget();

    expect($target->agent())->toBe(Agent::CLAUDE_CODE)
        ->and($target->skillsDirectoryRelative())
        ->toBe('.claude/skills')
        ->and($target->guidelinesFileRelative())
        ->toBe('CLAUDE.md');
});

it('plans one PendingWrite per skill, named `{name}/SKILL.md` under skills dir', function (): void {
    $target = new ClaudeCodeTarget();
    $writes = $target->plan(
        skills: [
            makeSkill('foo', ['name' => 'foo'], '# Foo'),
            makeSkill('bar', ['name' => 'bar'], '# Bar'),
        ],
        guidelines: [],
    );

    expect($writes)->toHaveCount(2)
        ->and($writes[0])
        ->toBeInstanceOf(PendingWrite::class)
        ->and($writes[0]->relativePath)
        ->toBe('.claude/skills/foo/SKILL.md')
        ->and($writes[1]->relativePath)
        ->toBe('.claude/skills/bar/SKILL.md');
});

it('embeds frontmatter at the top of each skill file', function (): void {
    $target = new ClaudeCodeTarget();
    $writes = $target->plan(
        skills: [makeSkill('foo', ['name' => 'foo', 'description' => 'A foo skill.'], 'Body content.')],
        guidelines: [],
    );

    expect($writes[0]->content)->toContain('---')
        ->toContain('name: foo')
        ->toContain('description:')
        ->toContain('Body content.');
});

it('omits the frontmatter block when frontmatter is empty', function (): void {
    $target = new ClaudeCodeTarget();
    $writes = $target->plan(
        skills: [makeSkill('foo', [], 'Just body.')],
        guidelines: [],
    );

    expect($writes[0]->content)->toBe('Just body.');
});

it('formats guidelines body markerless for CLAUDE.md (0.12.0: write handled centrally by SyncEngine, not plan())', function (): void {
    $target = new ClaudeCodeTarget();

    // The guideline DESTINATION is declared by guidelinesFileRelative().
    expect($target->guidelinesFileRelative())->toBe('CLAUDE.md');

    // plan() no longer emits a guideline write — only per-skill writes.
    expect($target->plan(skills: [], guidelines: [makeGuideline('g', 'G')]))
        ->toBeEmpty();

    // The markerless guideline body assembles the bodies with `---` separators
    // and carries NO boost-core marker comments.
    $body = $target->formatGuidelinesContent([
        makeGuideline('conventions', '# Conventions\n\nFoo.'),
        makeGuideline('style', '# Style\n\nBar.'),
    ]);
    expect($body)
        ->toContain('# Conventions')
        ->toContain('# Style')
        ->toContain('---')
        ->not->toContain('boost-core:guidelines');
});

it('plan emits only skill writes (no guideline write) — 0.12.0', function (): void {
    $target = new ClaudeCodeTarget();

    expect($target->plan(skills: [makeSkill('foo', [], 'body')], guidelines: []))
        ->toHaveCount(1)
        ->and($target->plan(skills: [makeSkill('foo', [], 'body')], guidelines: [])[0]->relativePath)
        ->toBe('.claude/skills/foo/SKILL.md');

    // Even WITH guidelines, plan() emits only the skill write.
    $both = $target->plan(skills: [makeSkill('foo', [], 'F')], guidelines: [makeGuideline('g', 'G')]);
    expect($both)->toHaveCount(1)
        ->and($both[0]->relativePath)->toBe('.claude/skills/foo/SKILL.md');
});
