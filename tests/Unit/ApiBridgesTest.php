<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * 0.22.0 @api bridges that let project-boost-laravel (the deepest consumer) run
 * on frozen surface without reaching engine-internal classes:
 *  - Agent::target() — agent value → its AgentTarget (drops 9 concrete classes)
 *  - BoostConfig::load() — read a project's config without the @internal loader
 */
it('Agent::target() maps every case to a matching @api AgentTarget', function (): void {
    foreach (Agent::cases() as $agent) {
        $target = $agent->target();

        expect($target)->toBeInstanceOf(AgentTarget::class)
            ->and($target->agent())->toBe($agent)
            ->and($target->agent()->value)->toBe($agent->value);
    }
});

it('Agent::target() lets a wrapper compute emit paths from agent-value strings (no concrete classes)', function (): void {
    // The project-boost-laravel pattern: list<string> active agents → targets.
    $activeAgents = ['claude-code', 'cursor'];
    $targets = array_map(static fn (string $a): AgentTarget => Agent::from($a)->target(), $activeAgents);

    $paths = array_map(static fn (AgentTarget $t): string => $t->skillsDirectoryRelative() . '/' . $t->skillRelativePathForName('foo'), $targets);

    expect($paths)->toBe([
        '.claude/skills/foo/SKILL.md',
        '.cursor/skills/foo/SKILL.md',
    ]);
});

it('BoostConfig::load() reads a project boost.php without the @internal loader', function (): void {
    $dir = sys_get_temp_dir() . '/boost-config-load-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR]);\n",
    );

    try {
        $config = BoostConfig::load($dir);

        expect($config)->toBeInstanceOf(BoostConfig::class)
            ->and($config->agents)->toBe([Agent::CLAUDE_CODE, Agent::CURSOR]);
    } finally {
        @unlink($dir . '/boost.php');
        @rmdir($dir);
    }
});
