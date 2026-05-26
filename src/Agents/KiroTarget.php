<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;
use SanderMuller\BoostCore\Sync\PendingWrite;

final class KiroTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::KIRO;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.kiro/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }

    /**
     * Kiro has no dedicated command directory — committed skills under
     * `.kiro/skills/<name>/` are invocable as `/<name>` slash-commands, so
     * a "command" maps to a skill-shaped emit. `planCommands` writes each
     * command as `.kiro/skills/<name>/SKILL.md` instead of a per-command
     * file in a separate directory. `commandsDirectoryRelative()` stays
     * null so gitignore/listing logic doesn't double-count the path.
     *
     * @return array{writes: list<PendingWrite>, warnings: list<string>}
     */
    public function planCommands(array $commands): array
    {
        $writes = [];
        $warnings = [];
        foreach ($commands as $command) {
            $transpiled = $this->transpileCommandBody($command);
            $writes[] = new PendingWrite(
                relativePath: $this->skillsDirectoryRelative() . '/' . $command->name . '/' . self::SKILL_FILE,
                content: $this->renderFrontmatter($command->frontmatter) . $transpiled->content,
            );
            foreach ($transpiled->warnings as $warning) {
                $warnings[] = sprintf('[%s] %s: %s', $this->agent()->value, $command->name, $warning);
            }
        }

        return ['writes' => $writes, 'warnings' => $warnings];
    }

    /**
     * Kiro accepts `$ARGUMENTS` natively and `${N}` (brace form) for
     * positional access. Bare `$N` is also accepted in current Kiro
     * builds but the docs only show `${N}` — we emit the documented
     * shape. Named placeholders are NOT documented for Kiro; emit
     * verbatim + warn so the operator can decide whether to author the
     * command differently for Kiro.
     */
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $out = '';
        $usedNamed = [];
        foreach ($tokens as $token) {
            $out .= match ($token->kind) {
                ArgumentToken::KIND_LITERAL => $token->value,
                ArgumentToken::KIND_ARGUMENTS => '$ARGUMENTS',
                ArgumentToken::KIND_POSITIONAL => '${' . $token->position . '}',
                ArgumentToken::KIND_NAMED => $this->collectNamed($token->value, $usedNamed),
                default => '',
            };
        }

        $warnings = [];
        if ($usedNamed !== []) {
            sort($usedNamed);
            $warnings[] = sprintf(
                'Kiro does not document named placeholders; `$%s` emitted verbatim. Use `$ARGUMENTS` (unsplit) or `${1}`/`${2}` (positional) for cross-agent portability.',
                implode('`, `$', $usedNamed),
            );
        }

        return new CommandTranspileResult(content: $out, warnings: $warnings);
    }

    /**
     * @param  list<string>  $usedNamed  mutated by reference
     */
    private function collectNamed(string $name, array &$usedNamed): string
    {
        if (! in_array($name, $usedNamed, true)) {
            $usedNamed[] = $name;
        }

        return '$' . $name;
    }
}
