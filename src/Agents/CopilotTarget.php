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

    /**
     * Copilot reads project skills from `.github/skills`, `.claude/skills`,
     * OR `.agents/skills` interchangeably (GitHub Docs: Adding agent skills,
     * 2025-12-18 Changelog: "GitHub Copilot now supports Agent Skills").
     * Boost-core routes Copilot to the shared `.agents/skills` pool — same
     * directory CodexTarget emits to. Consolidates the surface so consumers
     * with Copilot + Codex active don't duplicate skill files across two
     * directories. Copilot-only configs still get skills via this pool —
     * CopilotTarget's own emission keeps `.agents/skills` populated.
     */
    public function skillsDirectoryRelative(): string
    {
        return '.agents/skills';
    }

    /**
     * Copilot reads root-level `AGENTS.md` for repository-wide instructions
     * (GitHub Changelog 2025-08-28; expanded across cloud-agent, CLI, and
     * JetBrains surfaces through 2026). Same shared `AGENTS.md` target used
     * by {@see CodexTarget}, {@see CursorTarget}, {@see JunieTarget},
     * {@see KiroTarget}, {@see OpenCodeTarget}, {@see AmpTarget} — multiple
     * agents reading the same file is the established pattern and
     * `FileWriter`'s ManagedRegion merge handles concurrent writes safely.
     *
     * Skill + command surfaces (`.github/skills/`, `.github/prompts/`)
     * stay Copilot-specific; those have no equivalent on other targets.
     */
    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
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
