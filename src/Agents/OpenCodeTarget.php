<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use Override;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;

/**
 * @internal
 */
final class OpenCodeTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::OPENCODE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.opencode/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }

    public function commandsDirectoryRelative(): string
    {
        return '.opencode/commands';
    }

    /**
     * OpenCode natively accepts the canonical syntax: `$ARGUMENTS`,
     * one-indexed `$N`, and named `$NAME` (uppercase per OpenCode's
     * documented examples). Passthrough; named placeholders convert
     * to uppercase to match the convention.
     */
    #[Override]
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $out = '';
        foreach ($tokens as $token) {
            $out .= match ($token->kind) {
                ArgumentToken::KIND_LITERAL => $token->value,
                ArgumentToken::KIND_ARGUMENTS => '$ARGUMENTS',
                ArgumentToken::KIND_POSITIONAL => '$' . $token->position,
                ArgumentToken::KIND_NAMED => '$' . strtoupper($token->value),
                default => '',
            };
        }

        return new CommandTranspileResult(content: $out);
    }
}
