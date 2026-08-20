<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Sync\SkillSourceCollisionException;

/**
 * Enforces the ship-closure invariant after collision resolution: for every
 * shipped skill X and every name in X's `boost-requires`, a skill of that
 * name ships too — rescuing tag-dropped candidates where needed (the §4.2
 * fixpoint in `internal/specs/skill-dependencies.md`).
 *
 * Rescue rules, in the order the fixpoint applies them per demanded name:
 *
 *  1. Already shipped (host, kept vendor, or earlier rescue) → satisfied.
 *  2. No non-excluded retained candidate → ONE aggregated warning per name
 *     (`excluded` when a `withExcludedSkills()` entry blocked every
 *     provider, `missing` otherwise). The dependent still ships, degraded —
 *     exactly the pre-feature status quo, minus the silence.
 *  3. One provider holding two retained candidates of the demanded name →
 *     {@see SkillSourceCollisionException}, mirroring the kept-path
 *     same-provider guards (`InjectedVendorMerger`,
 *     `RemoteSkillSyncCoordinator`). Never bypassed by `$force` — the kept
 *     path has no force bypass either. Detected lazily, only for demanded
 *     names: a never-demanded duplicate in the retained pool is as invisible
 *     as it was before rescue existed.
 *  4. Candidates from two+ providers → {@see CollidingSkillsException}
 *     unless `$force` — the SAME rule `SkillResolver` applies to kept
 *     skills; a collision that tag filtering used to hide does not get a
 *     silent first-wins pass because it surfaced via rescue.
 *  5. First candidate in provider-precedence order is rescued; its own
 *     requires join the demand queue (transitive).
 *
 * Termination: every rescue moves a name into the shipped set and names are
 * finite, so cycles (`a ⇄ b`) simply co-ship — the shipped check is the
 * visited set.
 *
 * @internal
 */
final class SkillDependencyResolver
{
    /**
     * @param  list<Skill>  $resolved  Post-`SkillResolver` shipped skills.
     * @param  array<string, array{tagMismatch: list<Skill>, excluded: list<Skill>}>  $retainedDrops  Per-provider retained drops, insertion order = precedence order.
     * @return array{
     *   skills: list<Skill>,
     *   pulls: list<array{name: string, requiredBy: string, vendor: string}>,
     *   warnings: list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>,
     *   malformedRequires: list<string>,
     * }  `skills` is the resolved list plus rescues in rescue order. `pulls` records each rescue with its first demander. `warnings` aggregate per unsatisfiable name, dependents in first-demanded order. `malformedRequires` lists shipped skills whose `boost-requires` was unparseable (sync warns; `boost validate` errors).
     *
     * @throws CollidingSkillsException
     * @throws SkillSourceCollisionException
     */
    public function resolve(array $resolved, array $retainedDrops, bool $force = false): array
    {
        /** @var array<string, Skill> $shipped */
        $shipped = [];
        /** @var list<array{0: string, 1: string}> $queue  [requiredBy, demanded name] edges */
        $queue = [];
        /** @var list<string> $malformedRequires */
        $malformedRequires = [];

        foreach ($resolved as $skill) {
            $shipped[$skill->name] = $skill;
        }

        foreach ($resolved as $skill) {
            $this->enqueueDemands($skill, $queue, $malformedRequires);
        }

        [$candidates, $excludedNames] = $this->indexRetained($retainedDrops);

        /** @var list<array{name: string, requiredBy: string, vendor: string}> $pulls */
        $pulls = [];
        /** @var array<string, array{dependents: list<string>, reason: 'excluded'|'missing'}> $pending */
        $pending = [];

        // Shift-queue, NOT an index-for over a precomputed count: the loop body
        // APPENDS transitive demands to $queue, so the bound must be re-read
        // every iteration or rescued skills' own requires are silently skipped.
        while ($queue !== []) {
            [$requiredBy, $name] = array_shift($queue);
            if (isset($shipped[$name])) {
                continue;
            }

            $named = $candidates[$name] ?? [];
            if ($named === []) {
                $reason = isset($excludedNames[$name]) ? 'excluded' : 'missing';
                $pending[$name] ??= ['dependents' => [], 'reason' => $reason];
                if (! in_array($requiredBy, $pending[$name]['dependents'], true)) {
                    $pending[$name]['dependents'][] = $requiredBy;
                }

                continue;
            }

            $this->assertResolvableCandidates($name, $named, $force);

            $rescued = $named[0];
            $shipped[$name] = $rescued['skill'];
            $pulls[] = ['name' => $name, 'requiredBy' => $requiredBy, 'vendor' => $rescued['vendor']];
            $this->enqueueDemands($rescued['skill'], $queue, $malformedRequires);
        }

        $warnings = [];
        foreach ($pending as $name => $info) {
            $warnings[] = ['name' => $name, 'dependents' => $info['dependents'], 'reason' => $info['reason']];
        }

        return [
            'skills' => array_values($shipped),
            'pulls' => $pulls,
            'warnings' => $warnings,
            'malformedRequires' => $malformedRequires,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $queue  in-place
     * @param  list<string>  $malformedRequires  in-place
     */
    private function enqueueDemands(Skill $skill, array &$queue, array &$malformedRequires): void
    {
        if (! $skill->requiresValid) {
            $malformedRequires[] = $skill->name;
        }

        foreach ($skill->requires as $dep) {
            $queue[] = [$skill->name, $dep];
        }
    }

    /**
     * Index the retained drops by skill name. Excluded drops contribute only
     * their names — an exclude removes that provider from the candidate set
     * (its pre-resolution effect today), so another provider's tag-dropped
     * candidate can still satisfy the demand.
     *
     * @param  array<string, array{tagMismatch: list<Skill>, excluded: list<Skill>}>  $retainedDrops
     * @return array{0: array<string, list<array{vendor: string, skill: Skill}>>, 1: array<string, true>}
     */
    private function indexRetained(array $retainedDrops): array
    {
        /** @var array<string, list<array{vendor: string, skill: Skill}>> $candidates */
        $candidates = [];
        /** @var array<string, true> $excludedNames */
        $excludedNames = [];

        foreach ($retainedDrops as $vendor => $groups) {
            foreach ($groups['tagMismatch'] as $skill) {
                $candidates[$skill->name][] = ['vendor' => (string) $vendor, 'skill' => $skill];
            }

            foreach ($groups['excluded'] as $skill) {
                $excludedNames[$skill->name] = true;
            }
        }

        return [$candidates, $excludedNames];
    }

    /**
     * @param  list<array{vendor: string, skill: Skill}>  $named  Candidates for one demanded name, precedence order.
     *
     * @throws CollidingSkillsException
     * @throws SkillSourceCollisionException
     */
    private function assertResolvableCandidates(string $name, array $named, bool $force): void
    {
        /** @var list<string> $vendors */
        $vendors = [];
        foreach ($named as $candidate) {
            if (in_array($candidate['vendor'], $vendors, true)) {
                throw new SkillSourceCollisionException(sprintf(
                    'dependency rescue: provider `%s` holds multiple retained skills named `%s`. Rename one or fix the source.',
                    $candidate['vendor'],
                    $name,
                ));
            }

            $vendors[] = $candidate['vendor'];
        }

        if (count($vendors) > 1 && ! $force) {
            throw new CollidingSkillsException(name: $name, vendors: $vendors);
        }
    }
}
