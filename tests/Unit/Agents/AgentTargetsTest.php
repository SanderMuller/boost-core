<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;

/**
 * @return list<array{AgentTarget, Agent, string, ?string}> [target, agent, skillsDir, guidelinesFile]
 */
function allTargets(): array
{
    return [
        [new ClaudeCodeTarget(), Agent::CLAUDE_CODE, '.claude/skills', 'CLAUDE.md'],
        [new CursorTarget(), Agent::CURSOR, '.cursor/skills', 'AGENTS.md'],
        // Copilot reads AGENTS.md (Changelog 2025-08-28) + project skills
        // from .github/skills | .claude/skills | .agents/skills interchangeably
        // (Changelog 2025-12-18). 0.9.1 routes BOTH guidelines AND skills to
        // the shared `.agents/` pool — no separate `.github/copilot-instructions.md`,
        // no separate `.github/skills/` emission. Copilot still reads them
        // via the shared pool.
        [new CopilotTarget(), Agent::COPILOT, '.agents/skills', 'AGENTS.md'],
        [new CodexTarget(), Agent::CODEX, '.agents/skills', 'AGENTS.md'],
        [new GeminiTarget(), Agent::GEMINI, '.gemini/skills', 'GEMINI.md'],
        [new JunieTarget(), Agent::JUNIE, '.junie/skills', 'AGENTS.md'],
        [new KiroTarget(), Agent::KIRO, '.kiro/skills', 'AGENTS.md'],
        [new OpenCodeTarget(), Agent::OPENCODE, '.opencode/skills', 'AGENTS.md'],
        [new AmpTarget(), Agent::AMP, '.amp/skills', 'AGENTS.md'],
    ];
}

it('every Agent enum case has a matching AgentTarget', function (): void {
    $covered = array_map(static fn (array $row): Agent => $row[1], allTargets());
    expect(Agent::cases())->each->toBeIn($covered)
        ->and($covered)
        ->toHaveSameSize(Agent::cases());
});

it('each target reports the correct agent + paths', function (): void {
    foreach (allTargets() as [$target, $agent, $skillsDir, $guidelinesFile]) {
        expect($target->agent())->toBe($agent)
            ->and($target->skillsDirectoryRelative())
            ->toBe($skillsDir)
            ->and($target->guidelinesFileRelative())
            ->toBe($guidelinesFile);
    }
});

it('CopilotTarget emits guidelines into root AGENTS.md (joins the shared AGENTS.md pool, no `.github/copilot-instructions.md`)', function (): void {
    $guideline = new Guideline(
        name: 'sample',
        description: null,
        frontmatter: ['name' => 'sample'],
        body: 'body',
        sourcePath: '/fake',
        sourceVendor: null,
    );
    $writes = (new CopilotTarget())->plan([], [$guideline]);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]->relativePath)->toBe('AGENTS.md')
        ->and($writes[0]->relativePath)->not->toBe('.github/copilot-instructions.md');
});

it('CopilotTarget emits skills into `.agents/skills/` (joins the shared skills pool, no `.github/skills/`)', function (): void {
    $skill = new Skill(
        name: 'foo',
        description: null,
        frontmatter: ['name' => 'foo'],
        body: 'body',
        sourcePath: '/fake',
        sourceVendor: null,
    );
    $writes = (new CopilotTarget())->plan([$skill], []);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]->relativePath)->toBe('.agents/skills/foo/SKILL.md')
        ->and($writes[0]->relativePath)->not->toStartWith('.github/skills/');
});

it('every target plans at least one skill file when given one skill', function (): void {
    $skill = new Skill(
        name: 'foo',
        description: null,
        frontmatter: ['name' => 'foo'],
        body: 'body',
        sourcePath: '/fake',
        sourceVendor: null,
    );

    foreach (allTargets() as [$target, $agent, $skillsDir]) {
        $writes = $target->plan([$skill], []);

        expect($writes)->toHaveCount(1)
            ->and($writes[0]->relativePath)
            ->toBe($skillsDir . '/foo/SKILL.md');
    }
});
