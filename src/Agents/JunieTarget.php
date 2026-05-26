<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;

final class JunieTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::JUNIE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.junie/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }

    public function commandsDirectoryRelative(): string
    {
        return '.junie/commands';
    }

    /**
     * Junie requires every argument named AND required (per docs:
     * https://junie.jetbrains.com/docs/custom-slash-commands.html;
     * positional-support tracked at JUNIE-2422). Canonical → Junie:
     *
     *  - `$ARGUMENTS`  → `$args` (single synthetic named arg)
     *  - `$N`          → `$arg<N>` + WARN (synthetic name; operator
     *                    must declare it in the source frontmatter
     *                    `arguments:` list to satisfy Junie's
     *                    all-required contract).
     *  - `$name`       → `$name` passthrough; declared names should
     *                    be in `arguments:`.
     */
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $out = '';
        $autoNamedPositions = [];
        foreach ($tokens as $token) {
            $out .= match ($token->kind) {
                ArgumentToken::KIND_LITERAL => $token->value,
                ArgumentToken::KIND_ARGUMENTS => '$args',
                ArgumentToken::KIND_POSITIONAL => $this->collectPositional($token->position ?? 0, $autoNamedPositions),
                ArgumentToken::KIND_NAMED => '$' . $token->value,
                default => '',
            };
        }

        $warnings = [];
        if ($autoNamedPositions !== []) {
            sort($autoNamedPositions);
            $warnings[] = sprintf(
                'Junie requires named+required args; positional `$%s` auto-named to `$arg%s` — declare them in the source frontmatter `arguments:` list so Junie can surface the required-fields prompt.',
                implode('`, `$', $autoNamedPositions),
                implode('`, `$arg', $autoNamedPositions),
            );
        }

        return new CommandTranspileResult(content: $out, warnings: $warnings);
    }

    /**
     * @param  list<int>  $autoNamedPositions  mutated by reference
     */
    private function collectPositional(int $position, array &$autoNamedPositions): string
    {
        if (! in_array($position, $autoNamedPositions, true)) {
            $autoNamedPositions[] = $position;
        }

        return '$arg' . $position;
    }
}
