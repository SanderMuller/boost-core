<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

/**
 * Antigravity reads the same two paths Codex and Copilot already use: root
 * `AGENTS.md` for guidelines, `.agents/skills` for skills (matching
 * `laravel/boost`'s own Antigravity agent). Selecting it therefore adds no new
 * file when one of those agents is already selected — it exists so an
 * Antigravity-only project can pick its agent in `boost install` and receive
 * the paths it reads, instead of having to know that "Codex" writes them.
 *
 * No command surface: Antigravity publishes no committable command directory,
 * so {@see AgentTarget::commandsDirectoryRelative()} stays null.
 *
 * @internal
 */
final class AntigravityTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::ANTIGRAVITY;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.agents/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
