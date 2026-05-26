<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;

final class ClaudeCodeTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::CLAUDE_CODE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.claude/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'CLAUDE.md';
    }

    public function commandsDirectoryRelative(): string
    {
        return '.claude/commands';
    }

    /**
     * Claude Code as of 2026 uses zero-indexed `$N` (`$0` = first arg).
     * The canonical syntax is one-indexed (matches OpenCode, Codex,
     * Kiro, and most human intuition) — so positional placeholders
     * convert DOWN by one. `$ARGUMENTS` and `$name` pass through
     * unchanged; Claude accepts both natively.
     */
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $out = '';
        foreach ($tokens as $token) {
            $out .= match ($token->kind) {
                ArgumentToken::KIND_LITERAL => $token->value,
                ArgumentToken::KIND_ARGUMENTS => '$ARGUMENTS',
                ArgumentToken::KIND_POSITIONAL => '$' . (($token->position ?? 1) - 1),
                ArgumentToken::KIND_NAMED => '$' . $token->value,
                default => '',
            };
        }

        return new CommandTranspileResult(content: $out);
    }
}
