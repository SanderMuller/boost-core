<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * The choices `boost remote` makes about a discovered skill set, as pure
 * functions over {@see DiscoveredSkill}s.
 *
 * Kept out of the command because none of it is about the console: what a
 * dependency closure contains, which tags a selection needs, and what a row
 * should warn about are all questions about the skills themselves — and they
 * are the parts most worth testing directly.
 *
 * @internal
 */
final class RemoteSelection
{
    /**
     * Characters of a skill's description shown in the picker. Enough to tell
     * two skills apart, short enough that a row plus its annotations still fits
     * a normal terminal without wrapping.
     */
    public const DESCRIPTION_WIDTH = 80;

    /**
     * Close the picked set over `boost-requires`, within this repo.
     *
     * A remote skill's dependency is only fetched when the config names it:
     * `RemoteSkillIngester` loads the declared refs and nothing else, so
     * `SkillDependencyResolver` has no candidate to rescue and sync degrades
     * to a "missing dependency" warning. Pulling the dependency in here is
     * what makes the declared set self-sufficient.
     *
     * @param  list<DiscoveredSkill>  $discovered  everything the repo offers
     * @param  list<string>  $picked  names the operator checked
     * @return array{
     *   names: list<string>,
     *   pulled: array<string, string>,
     *   missing: array<string, list<string>>,
     * }  `pulled` maps each added name to the first skill that demanded it;
     *    `missing` maps an unsatisfiable name to the skills demanding it.
     */
    public static function closeDependencies(array $discovered, array $picked): array
    {
        $selectable = [];
        foreach ($discovered as $skill) {
            if ($skill->isSelectable()) {
                $selectable[$skill->name] = $skill;
            }
        }

        $chosen = [];
        $queue = [];
        foreach ($picked as $name) {
            if (isset($selectable[$name])) {
                $chosen[$name] = true;
                $queue[] = $name;
            }
        }

        $pulled = [];
        $missing = [];

        // Breadth-first over the demand graph. `$chosen` doubles as the visited
        // set, so a dependency cycle simply co-ships instead of looping.
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($selectable[$current]->requires as $required) {
                if (isset($chosen[$required])) {
                    continue;
                }

                if (! isset($selectable[$required])) {
                    $missing[$required][] = $current;

                    continue;
                }

                $chosen[$required] = true;
                $pulled[$required] = $current;
                $queue[] = $required;
            }
        }

        return [
            'names' => array_keys($chosen),
            'pulled' => $pulled,
            'missing' => array_map(
                static fn (array $dependents): array => array_values(array_unique($dependents)),
                $missing,
            ),
        ];
    }

    /**
     * Tags the selection needs that the project doesn't declare.
     *
     * A remote skill is tag-filtered exactly like a vendor one, so declaring a
     * skill whose tags aren't enabled writes config that never produces a
     * file — the silent outcome this check exists to prevent. An empty
     * `withTags()` disables filtering altogether, so nothing is missing then.
     *
     * @param  list<DiscoveredSkill>  $selected
     * @param  list<string>  $projectTags
     * @return list<string>
     */
    public static function missingTags(array $selected, array $projectTags): array
    {
        if ($projectTags === []) {
            return [];
        }

        $missing = [];
        foreach ($selected as $skill) {
            foreach ($skill->tags as $tag) {
                if (! in_array($tag, $projectTags, true)) {
                    $missing[$tag] = true;
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * The picker row for one selectable skill: name, trimmed description, and
     * whatever the operator needs to know before ticking it.
     *
     * @param  array<string, string>  $existingSkills  skill name => origin ('host' or a vendor name)
     * @param  list<string>  $projectTags
     */
    public static function pickerLabel(
        DiscoveredSkill $skill,
        array $existingSkills,
        array $projectTags,
        bool $isExcluded,
    ): string {
        $label = $skill->name;

        if ($skill->description !== null && $skill->description !== '') {
            $label .= '  ' . self::truncate($skill->description, self::DESCRIPTION_WIDTH);
        }

        $notes = [];

        $origin = $existingSkills[$skill->name] ?? null;
        if ($origin === 'host') {
            $notes[] = 'your own .ai/ skill of this name wins — this copy would never ship';
        } elseif ($origin !== null) {
            $notes[] = sprintf('collides with %s, which fails the sync', $origin);
        }

        if ($isExcluded) {
            $notes[] = 'excluded via withExcludedSkills()';
        }

        // The gap only: naming an already-declared tag reads as "add this too".
        $missingTags = self::missingTags([$skill], $projectTags);
        if ($missingTags !== []) {
            $notes[] = sprintf('tags %s are not in withTags()', implode(', ', $missingTags));
        }

        return $notes === [] ? $label : $label . ' — ' . implode('; ', $notes);
    }

    private static function truncate(string $value, int $width): string
    {
        $collapsed = (string) preg_replace('/\s+/', ' ', trim($value));

        return mb_strlen($collapsed) <= $width
            ? $collapsed
            : mb_substr($collapsed, 0, $width - 1) . '…';
    }
}
