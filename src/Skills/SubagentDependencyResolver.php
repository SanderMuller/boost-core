<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Resolves `boost-requires: "subagent:<name>"` demands: rescues a subagent tag
 * filtering would have dropped, and aggregates the demands nothing can satisfy.
 *
 * The rule matches skill dependencies exactly — "whenever a skill ships, every
 * name in its `boost-requires` ships too" — because an author declaring a hard
 * hand-off is asserting the flow is broken without it, which outranks topic
 * scoping.
 *
 * Deliberately a SIBLING of {@see SkillDependencyResolver}, not a reuse of it.
 * That class is typed on `Skill` throughout (its queue, its rescue bookkeeping,
 * its collision assertions), and generalising it would touch the most
 * load-bearing resolution path in the engine for no behavioural gain. What
 * matters is that the OBSERVABLE contract is identical: the same
 * `'excluded'|'missing'` reasons, the same aggregate-per-name warning shape,
 * and rescue that reports as INFO.
 *
 * One structural difference, and it is inherent rather than a simplification:
 * the demand graph is one edge deep. A skill demands a subagent; a subagent
 * demands nothing. So there is no transitive queue and no cycle to handle.
 *
 * @internal
 */
final class SubagentDependencyResolver
{
    /**
     * @param  list<Skill>  $shippedSkills  Post-resolution skills whose demands count.
     * @param  list<Subagent>  $resolved  Subagents that survived tag filtering + collision resolution.
     * @param  array<string, array{tagMismatch: list<Subagent>, excluded: list<Subagent>}>  $retainedDrops  Per-vendor drops, insertion order = precedence order.
     * @param  bool  $force  Accept an ambiguous rescue in declaration order instead of failing.
     * @return array{
     *   subagents: list<Subagent>,
     *   pulls: list<array{name: string, requiredBy: string, vendor: string}>,
     *   warnings: list<array{name: string, dependents: list<string>, reason: 'excluded'|'missing'}>,
     * }  `subagents` is the resolved list plus rescues, in rescue order. `pulls` records each rescue with its first demander. `warnings` aggregate per unsatisfiable name, dependents in first-demanded order.
     */
    public function resolve(array $shippedSkills, array $resolved, array $retainedDrops, bool $force = false): array
    {
        /** @var array<string, Subagent> $shipped */
        $shipped = [];
        foreach ($resolved as $subagent) {
            $shipped[$subagent->name] = $subagent;
        }

        [$candidates, $excludedNames] = $this->indexRetained($retainedDrops);

        /** @var list<array{name: string, requiredBy: string, vendor: string}> $pulls */
        $pulls = [];
        /** @var array<string, array{dependents: list<string>, reason: 'excluded'|'missing'}> $pending */
        $pending = [];

        foreach ($shippedSkills as $skill) {
            foreach ($skill->requiredSubagents as $name) {
                if (isset($shipped[$name])) {
                    continue;
                }

                $named = $candidates[$name] ?? [];
                if ($named === []) {
                    // Tells a consumer "you filtered this out" apart from "the
                    // package is broken".
                    $reason = isset($excludedNames[$name]) ? 'excluded' : 'missing';
                    $pending[$name] ??= ['dependents' => [], 'reason' => $reason];
                    if (! in_array($skill->name, $pending[$name]['dependents'], true)) {
                        $pending[$name]['dependents'][] = $skill->name;
                    }

                    continue;
                }

                // Rescue must not be a back door around the collision rule the
                // normal path enforces: two vendors holding one name is fatal
                // whether or not tag filtering dropped them first.
                $this->assertResolvableCandidates($name, $named, $force);

                // First in precedence order, like the skill rescue.
                $winner = $named[0];
                $shipped[$name] = $winner['subagent'];
                $pulls[] = ['name' => $name, 'requiredBy' => $skill->name, 'vendor' => $winner['vendor']];
            }
        }

        $warnings = [];
        foreach ($pending as $name => $entry) {
            $warnings[] = ['name' => $name, 'dependents' => $entry['dependents'], 'reason' => $entry['reason']];
        }

        return [
            'subagents' => array_values($shipped),
            'pulls' => $pulls,
            'warnings' => $warnings,
        ];
    }

    /**
     * Mirrors {@see SkillDependencyResolver::assertResolvableCandidates()}: a
     * rescue may not resolve an ambiguity the normal path refuses to.
     *
     * @param  list<array{vendor: string, subagent: Subagent}>  $named
     *
     * @throws CollidingSubagentsException
     */
    private function assertResolvableCandidates(string $name, array $named, bool $force): void
    {
        /** @var list<string> $vendors */
        $vendors = [];
        foreach ($named as $candidate) {
            if (! in_array($candidate['vendor'], $vendors, true)) {
                $vendors[] = $candidate['vendor'];
            }
        }

        if (count($vendors) > 1 && ! $force) {
            throw new CollidingSubagentsException(name: $name, vendors: $vendors);
        }
    }

    /**
     * Index retained drops by name. Excluded drops contribute only their names:
     * an exclude removes that provider from the candidate set, so another
     * provider's tag-dropped copy can still satisfy the demand.
     *
     * @param  array<string, array{tagMismatch: list<Subagent>, excluded: list<Subagent>}>  $retainedDrops
     * @return array{0: array<string, list<array{vendor: string, subagent: Subagent}>>, 1: array<string, true>}
     */
    private function indexRetained(array $retainedDrops): array
    {
        /** @var array<string, list<array{vendor: string, subagent: Subagent}>> $candidates */
        $candidates = [];
        /** @var array<string, true> $excludedNames */
        $excludedNames = [];

        foreach ($retainedDrops as $vendor => $groups) {
            foreach ($groups['tagMismatch'] as $subagent) {
                $candidates[$subagent->name][] = ['vendor' => (string) $vendor, 'subagent' => $subagent];
            }

            foreach ($groups['excluded'] as $subagent) {
                $excludedNames[$subagent->name] = true;
            }
        }

        return [$candidates, $excludedNames];
    }
}
