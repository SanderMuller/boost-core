<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\SubagentNameScanner;
use SanderMuller\BoostCore\Sync\SyncEngine;

/**
 * Turns the subagent pipeline's outcomes into `SyncResult` diagnostics.
 *
 * Extracted from {@see SyncEngine} for the same
 * reason {@see SkillDependencyDiagnostics} was: the engine is already a known
 * god-object, and message construction is the part that does not need to live
 * there.
 *
 * Every diagnostic here is ADVISORY. None of them join `SyncResult::errors`,
 * because none of them should fail a `composer install`: a name collision is
 * the operator's to resolve, and an unsatisfiable dependency leaves the
 * dependent skill shipping in a degraded form rather than not at all.
 *
 * @internal
 */
final readonly class SubagentDiagnostics
{
    /**
     * Every diagnostic one resolution earns, in report order.
     *
     * @param  bool  $emitting  Whether any configured agent can receive subagents.
     *
     * @return list<Diagnostic>
     */
    public static function forResult(string $projectRoot, SubagentResolution $resolution, bool $emitting): array
    {
        return [
            ...self::overlaps($projectRoot, $resolution->subagents, $emitting),
            ...self::loadWarnings($resolution->loadWarnings),
            ...self::rescues($resolution->pulls),
            ...self::unsatisfiedDemands($resolution->dependencyWarnings),
        ];
    }

    /**
     * A name boost is about to emit that a file boost does NOT own also
     * declares. Reported at emission time because boost knows the names before
     * it writes them — earlier than Claude Code's own `/doctor` can say so.
     *
     * @param  list<Subagent>  $resolved
     * @param  bool  $emitting  False when no configured agent has a subagent surface — nothing is about to be written, so there is no collision to warn about.
     * @return list<Diagnostic>
     */
    public static function overlaps(string $projectRoot, array $resolved, bool $emitting): array
    {
        if (! $emitting) {
            return [];
        }

        $names = array_map(static fn (Subagent $subagent): string => $subagent->name, $resolved);

        $diagnostics = [];
        foreach ((new SubagentNameScanner())->unownedHolders($projectRoot, $names) as $name => $paths) {
            $diagnostics[] = Diagnostic::warning(null, sprintf(
                'subagent `%s` is also declared by a file boost does not own (%s). Claude Code loads only one of them, chosen by filesystem read order. Rename or remove one side — boost does not arbitrate.',
                $name,
                implode(', ', $paths),
            ));
        }

        return $diagnostics;
    }

    /**
     * Rescues, as INFO: the author's "this flow is broken without it" outranked
     * the consumer's topic scoping. Visible under `-v`, never alarming.
     *
     * @param  list<array{name: string, requiredBy: string, vendor: string}>  $pulls
     * @return list<Diagnostic>
     */
    public static function rescues(array $pulls): array
    {
        return array_map(
            static fn (array $pull): Diagnostic => Diagnostic::info(null, sprintf(
                'subagent `%s` (%s) shipped because `%s` requires it, despite tag filtering.',
                $pull['name'],
                $pull['vendor'],
                $pull['requiredBy'],
            )),
            $pulls,
        );
    }

    /**
     * Unsatisfiable demands, as warnings. `excluded` and `missing` read
     * differently on purpose: one says "you filtered this out", the other says
     * "nothing provides it".
     *
     * @param  list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>  $warnings
     * @return list<Diagnostic>
     */
    public static function unsatisfiedDemands(array $warnings): array
    {
        return array_map(
            static fn (array $warning): Diagnostic => Diagnostic::warning(null, sprintf(
                'subagent `%s` is required by %s but is %s. The skill ships degraded — its dispatch will fall back.',
                $warning['name'],
                implode(', ', array_map(static fn (string $dependent): string => '`' . $dependent . '`', $warning['dependents'])),
                $warning['reason'] === 'excluded'
                    ? 'excluded by this project (withExcludedSkills)'
                    : 'not provided by any installed package',
            )),
            $warnings,
        );
    }

    /**
     * Per-file load warnings, already phrased by {@see SubagentLoader}.
     *
     * @param  list<string>  $warnings
     * @return list<Diagnostic>
     */
    public static function loadWarnings(array $warnings): array
    {
        return array_map(
            static fn (string $warning): Diagnostic => Diagnostic::warning(null, $warning),
            $warnings,
        );
    }
}
