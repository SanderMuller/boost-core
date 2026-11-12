<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\Diagnostic;

/**
 * Warns when a host command and a resolved skill share a name.
 *
 * Commands and skills fan out independently, and host-wins resolution compares
 * skills with skills only. So a host `.ai/commands/<name>.md` and a skill named
 * `<name>` both land. An agent with a command directory offers two entry
 * points under one slash name; Kiro, which emits a command as a skill, writes
 * both to one path and the command overwrites the skill.
 *
 * Advisory only: boost does not pick a winner. Dropping the skill would also
 * drop it for agents with no command surface, and would leave a skill that
 * requires it degraded. The operator excludes the skill or renames one side.
 *
 * @internal
 */
final readonly class CommandSkillNameClash
{
    /**
     * @param  list<Command>  $commands  resolved host commands
     * @param  list<Skill>  $skills  resolved skills, after exclusion and tag filtering
     * @param  list<AgentTarget>  $agentTargets
     * @return list<Diagnostic>
     */
    public static function diagnostics(string $projectRoot, array $commands, array $skills, BoostConfig $config, array $agentTargets): array
    {
        if ($commands === [] || ! self::anyCommandSurface($commands, $config, $agentTargets)) {
            return [];
        }

        $skillsByName = [];
        foreach ($skills as $skill) {
            $skillsByName[$skill->name] = $skill;
        }

        $diagnostics = [];
        foreach ($commands as $command) {
            $skill = $skillsByName[$command->name] ?? null;
            if (! $skill instanceof Skill) {
                continue;
            }

            $diagnostics[] = Diagnostic::warning(null, self::message($projectRoot, $command, $skill));
        }

        return $diagnostics;
    }

    private static function message(string $projectRoot, Command $command, Skill $skill): string
    {
        $commandSource = self::relative($projectRoot, $command->sourcePath);
        $excludeKey = $skill->excludeKey();

        if ($excludeKey === null) {
            return sprintf(
                'command `%s` (%s) has the same name as the host skill `%s` (%s). Both publish `/%s` to each agent with a command surface, as two entry points or as one file that the command overwrites. Rename one of them.',
                $command->name,
                $commandSource,
                $skill->name,
                self::relative($projectRoot, $skill->sourcePath),
                $command->name,
            );
        }

        return sprintf(
            "command `%s` (%s) has the same name as the skill `%s` from %s. Both publish `/%s` to each agent with a command surface, as two entry points or as one file that the command overwrites. Exclude the skill with `withExcludedSkills(['%s'])` in boost.php, or rename the command.",
            $command->name,
            $commandSource,
            $skill->name,
            (string) $skill->sourceVendor,
            $command->name,
            $excludeKey,
        );
    }

    /**
     * Whether a configured agent emits commands at all. Asked of the plan, not
     * of `commandsDirectoryRelative()`: Kiro has no command directory but
     * emits each command as a skill, at the same path as a same-name skill.
     *
     * @param  list<Command>  $commands
     * @param  list<AgentTarget>  $agentTargets
     */
    private static function anyCommandSurface(array $commands, BoostConfig $config, array $agentTargets): bool
    {
        foreach ($agentTargets as $target) {
            if ($config->hasAgent($target->agent()) && $target->planCommands($commands)['writes'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The loaders record a resolved real path, so the project root is tried as
     * given and resolved (on macOS the temp dir sits behind a `/private` link).
     */
    private static function relative(string $projectRoot, string $path): string
    {
        foreach ([$projectRoot, (string) realpath($projectRoot)] as $root) {
            $prefix = rtrim($root, '/') . '/';
            if ($root !== '' && str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return $path;
    }
}
