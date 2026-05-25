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
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\Skill;

function makeSampleCommand(): Command
{
    return new Command(
        name: 'deploy',
        description: 'Ship it.',
        frontmatter: ['description' => 'Ship it.'],
        body: "Run the deploy.\n",
        sourcePath: '/src/deploy.md',
        sourceVendor: null,
    );
}

it('emits a command to each Markdown agent command directory', function (AgentTarget $target, string $expectedPath): void {
    $writes = $target->planCommands([makeSampleCommand()]);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]->relativePath)->toBe($expectedPath);
})->with([
    'claude' => [new ClaudeCodeTarget(), '.claude/commands/deploy.md'],
    'cursor' => [new CursorTarget(), '.cursor/commands/deploy.md'],
    'copilot' => [new CopilotTarget(), '.github/prompts/deploy.prompt.md'],
    'junie' => [new JunieTarget(), '.junie/commands/deploy.md'],
    'opencode' => [new OpenCodeTarget(), '.opencode/commands/deploy.md'],
    'amp' => [new AmpTarget(), '.agents/commands/deploy.md'],
]);

it('emits nothing for agents with no committable command target', function (AgentTarget $target): void {
    expect($target->commandsDirectoryRelative())->toBeNull()
        ->and($target->planCommands([makeSampleCommand()]))->toBeEmpty();
})->with([
    'gemini' => [new GeminiTarget()],
    'codex' => [new CodexTarget()],
]);

it('Kiro emits each command as a skill-shaped .kiro/skills/<name>/SKILL.md (its native slash-command surface)', function (): void {
    $writes = (new KiroTarget())->planCommands([makeSampleCommand()]);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]->relativePath)->toBe('.kiro/skills/deploy/SKILL.md')
        ->and($writes[0]->content)->toStartWith("---\n")
        ->and($writes[0]->content)->toContain('description:')
        ->and($writes[0]->content)->toContain('Run the deploy.');
});

it('Kiro keeps `commandsDirectoryRelative()` null so gitignore / dir tooling do not double-count', function (): void {
    expect((new KiroTarget())->commandsDirectoryRelative())->toBeNull()
        ->and((new KiroTarget())->gitignorePatterns())->not->toContain('.kiro/commands/');
});

it('keeps frontmatter for frontmatter-aware agents', function (): void {
    $content = (new ClaudeCodeTarget())->planCommands([makeSampleCommand()])[0]->content;

    expect($content)->toStartWith("---\n")
        ->and($content)->toContain('description:')
        ->and($content)->toContain('Run the deploy.');
});

it('drops frontmatter for Cursor and Amp — the whole file is the prompt', function (AgentTarget $target): void {
    $content = $target->planCommands([makeSampleCommand()])[0]->content;

    expect($content)->toBe("Run the deploy.\n");
})->with([
    'cursor' => [new CursorTarget()],
    'amp' => [new AmpTarget()],
]);

it('lists the command directory in gitignore patterns for a command-capable agent', function (): void {
    expect((new ClaudeCodeTarget())->gitignorePatterns())->toContain('.claude/commands/');
});

it('omits a command directory from gitignore patterns for an agent without one', function (): void {
    expect((new GeminiTarget())->gitignorePatterns())->not->toContain('.gemini/commands/');
});

it('Kiro command emit content is identical to the equivalent skill-shaped emit (same renderer hooks)', function (): void {
    // Belt-and-suspenders: Kiro emits a command into its skills surface,
    // so the rendered content must equal what an equivalent Skill would
    // render through formatSkillContent(). Deriving the expected value
    // from the same Skill object the base renderer would produce avoids
    // brittle hardcoded YAML — a harmless Yaml::dump formatting tweak
    // doesn't break this test; an actual divergence between Kiro's
    // command rendering and skill rendering does.
    $command = makeSampleCommand();
    $kiro = new KiroTarget();
    $write = $kiro->planCommands([$command])[0];

    $equivalentSkill = new Skill(
        name: $command->name,
        description: $command->description,
        frontmatter: $command->frontmatter,
        body: $command->body,
        sourcePath: $command->sourcePath,
        sourceVendor: $command->sourceVendor,
    );
    $expected = $kiro->formatSkillContent($equivalentSkill);

    expect($write->content)->toBe($expected);
});
