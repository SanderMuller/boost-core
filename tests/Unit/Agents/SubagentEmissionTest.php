<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\AntigravityTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Skills\Subagent;
use SanderMuller\BoostCore\Sync\PendingWrite;

function emissionSubagent(string $name = 'simplification-auditor', ?string $vendor = null): Subagent
{
    return new Subagent(
        name: $name,
        description: 'Adversarial cut pass.',
        frontmatter: ['name' => $name, 'description' => 'Adversarial cut pass.', 'disallowedTools' => 'Write, Edit'],
        body: "Judge the diff as somebody else's code.\n",
        sourcePath: '/src/' . $name . '.md',
        sourceVendor: $vendor,
    );
}

it('emits a vendor subagent under the boost subtree, namespaced by package suffix', function (): void {
    $writes = (new ClaudeCodeTarget())->planSubagents([
        emissionSubagent(vendor: 'sandermuller/boost-skills'),
    ]);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]->relativePath)
        ->toBe('.claude/agents/boost/sandermuller__boost-skills/simplification-auditor.md');
});

it('emits a host subagent under the reserved host segment', function (): void {
    $writes = (new ClaudeCodeTarget())->planSubagents([emissionSubagent('mine')]);

    expect($writes[0]->relativePath)->toBe('.claude/agents/boost/host/mine.md');
});

it('never lets a package segment collide with the host segment', function (): void {
    // Every vendor segment is a Composer name flattened with `__`, which a
    // single path segment can never be — so `host` needs no rejection path.
    $writes = (new ClaudeCodeTarget())->planSubagents([
        emissionSubagent('a', vendor: 'host/host'),
        emissionSubagent('b', vendor: 'acme/host'),
    ]);

    foreach ($writes as $write) {
        expect($write->relativePath)->toContain('__')
            ->and($write->relativePath)->not->toContain('/boost/host/');
    }
});

it('passes frontmatter through verbatim, including tool assertions', function (): void {
    $content = (new ClaudeCodeTarget())->planSubagents([emissionSubagent()])[0]->content;

    expect($content)->toContain('disallowedTools:')
        ->and($content)->toContain('Write, Edit')
        ->and($content)->toContain("Judge the diff as somebody else's code.");
});

it('emits nothing for a target with no subagent surface', function (AgentTarget $target): void {
    expect($target->subagentsDirectoryRelative())->toBeNull()
        ->and($target->planSubagents([emissionSubagent()]))->toBe([]);
})->with([
    'cursor' => [new CursorTarget()],
    'codex' => [new CodexTarget()],
    'gemini' => [new GeminiTarget()],
    'copilot' => [new CopilotTarget()],
    'junie' => [new JunieTarget()],
    'kiro' => [new KiroTarget()],
    'opencode' => [new OpenCodeTarget()],
    'amp' => [new AmpTarget()],
    'antigravity' => [new AntigravityTarget()],
]);

it('gitignores the boost subtree and never the scanned agents root', function (): void {
    $patterns = (new ClaudeCodeTarget())->gitignorePatterns();

    // The root holds hand-written definitions the operator tracks in git.
    expect($patterns)->toContain('.claude/agents/boost/')
        ->and($patterns)->not->toContain('.claude/agents/');
});

it('adds no subagent gitignore entry for a target without the surface', function (): void {
    expect((new CursorTarget())->gitignorePatterns())
        ->each->not->toContain('agents');
});

it('emits one file per subagent', function (): void {
    $writes = (new ClaudeCodeTarget())->planSubagents([
        emissionSubagent('one', vendor: 'acme/pack'),
        emissionSubagent('two', vendor: 'acme/pack'),
        emissionSubagent('three'),
    ]);

    expect($writes)->toHaveCount(3)
        ->and(array_map(static fn (PendingWrite $w): string => $w->relativePath, $writes))->toBe([
            '.claude/agents/boost/acme__pack/one.md',
            '.claude/agents/boost/acme__pack/two.md',
            '.claude/agents/boost/host/three.md',
        ]);
});
