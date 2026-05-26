<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;

final class CopilotTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::COPILOT;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.github/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return '.github/copilot-instructions.md';
    }

    public function commandsDirectoryRelative(): string
    {
        return '.github/prompts';
    }

    public function commandFileExtension(): string
    {
        return 'prompt.md';
    }

    /**
     * Copilot prompt files use VS Code's variable-substitution syntax:
     * `${input:variableName}` — prompted at run-time. Canonical
     * `$ARGUMENTS` → `${input:args}`, `$N` → `${input:argN}`, named
     * `$name` → `${input:name}`. Not lossy.
     */
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $out = '';
        foreach ($tokens as $token) {
            $out .= match ($token->kind) {
                ArgumentToken::KIND_LITERAL => $token->value,
                ArgumentToken::KIND_ARGUMENTS => '${input:args}',
                ArgumentToken::KIND_POSITIONAL => '${input:arg' . $token->position . '}',
                ArgumentToken::KIND_NAMED => '${input:' . $token->value . '}',
                default => '',
            };
        }

        return new CommandTranspileResult(content: $out);
    }
}
