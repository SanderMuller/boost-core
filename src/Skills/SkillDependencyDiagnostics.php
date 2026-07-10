<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Conventions\Diagnostic;

/**
 * Maps a dependency-resolution outcome onto command-facing diagnostics — the
 * shared reporter behind `boost validate` and `boost doctor`, fed from
 * `SyncEngine::resolveForInspection()` so both commands describe the exact
 * demand outcome a sync would produce.
 *
 * Severity mapping (`internal/specs/skill-dependencies.md` §4.4, §4.7):
 *  - ERROR per malformed `boost-requires` — sync only warns (a vendor typo
 *    must not cost a consumer the skill), but validate is the authoring-side
 *    CI gate where the typo gets fixed, so `--strict` hard-fails on it.
 *  - WARNING per unsatisfiable demand (missing / excluded).
 *  - INFO per cross-tag-boundary require among shipped skills — legal
 *    (rescue covers it) but an author-decision flag: the dep ships into
 *    projects that never opted into its tags.
 *
 * @internal
 */
final class SkillDependencyDiagnostics
{
    /**
     * The SYNC-side mapping — differs from {@see diagnostics()} in one
     * deliberate way: malformed `boost-requires` is a WARNING here (a vendor
     * typo must not cost the consumer anything at sync time) while the
     * validate/doctor mapping raises it as an ERROR. Rescue pulls are
     * sync-only (inspection reports the post-rescue set, not the journey).
     *
     * @param  list<array{name: string, requiredBy: string, vendor: string}>  $pulls
     * @param  list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>  $warnings
     * @param  list<string>  $malformedRequires
     * @return list<Diagnostic>
     */
    public static function syncDiagnostics(array $pulls, array $warnings, array $malformedRequires): array
    {
        $diagnostics = [];

        foreach ($pulls as $pull) {
            $diagnostics[] = Diagnostic::info(null, sprintf(
                'Dependency rescue: skill `%s` (from `%s`) ships because `%s` requires it.',
                $pull['name'],
                $pull['vendor'],
                $pull['requiredBy'],
            ));
        }

        $diagnostics = [...$diagnostics, ...self::warningDiagnostics($warnings)];

        foreach ($malformedRequires as $skillName) {
            $diagnostics[] = Diagnostic::warning(null, sprintf(
                'Skill `%s` declares a malformed `metadata.boost-requires` (must be a space-delimited string of skill names) — its dependencies were ignored this sync.',
                $skillName,
            ));
        }

        return $diagnostics;
    }

    /**
     * @param  list<Skill>  $skills  Post-rescue shipped set.
     * @param  list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>  $warnings
     * @param  list<string>  $malformedRequires
     * @return list<Diagnostic>
     */
    public static function diagnostics(array $skills, array $warnings, array $malformedRequires): array
    {
        $diagnostics = [];

        foreach ($malformedRequires as $skillName) {
            $diagnostics[] = Diagnostic::error(null, sprintf(
                'skill `%s` declares a malformed `metadata.boost-requires` — the value must be a space-delimited string of skill names. Its dependencies are ignored until fixed.',
                $skillName,
            ));
        }

        return [...$diagnostics, ...self::warningDiagnostics($warnings), ...self::crossTagBoundaryInfos($skills)];
    }

    /**
     * @param  list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>  $warnings
     * @return list<Diagnostic>
     */
    private static function warningDiagnostics(array $warnings): array
    {
        $diagnostics = [];
        foreach ($warnings as $warning) {
            $dependents = implode('`, `', $warning['dependents']);
            $diagnostics[] = Diagnostic::warning(null, $warning['reason'] === 'excluded'
                ? sprintf('skill dependency `%s` (required by `%s`) is excluded by withExcludedSkills() — the dependent ships without it.', $warning['name'], $dependents)
                : sprintf('skill dependency `%s` (required by `%s`) does not exist in any source.', $warning['name'], $dependents));
        }

        return $diagnostics;
    }

    /**
     * @param  list<Skill>  $skills
     * @return list<Diagnostic>
     */
    private static function crossTagBoundaryInfos(array $skills): array
    {
        /** @var array<string, Skill> $byName */
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }

        $infos = [];
        foreach ($skills as $skill) {
            foreach ($skill->requires as $required) {
                $dep = $byName[$required] ?? null;
                if ($dep instanceof Skill && array_diff($dep->tags, $skill->tags) !== []) {
                    $infos[] = Diagnostic::info(null, sprintf(
                        'skill `%s` requires `%s`, whose tags (`%s`) exceed the dependent\'s (`%s`) — dependency rescue ships it into projects that did not opt into those tags. Confirm this is a hard dependency.',
                        $skill->name,
                        $required,
                        implode(' ', $dep->tags),
                        $skill->tags === [] ? '(untagged)' : implode(' ', $skill->tags),
                    ));
                }
            }
        }

        return $infos;
    }
}
