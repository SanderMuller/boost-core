<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillDependencyResolver;

/**
 * Applies the operator's `~/.boost/user-scope.php` selection to one package's
 * skills.
 *
 * No entry for the package → every skill. An entry → the named skills plus,
 * transitively, every skill of the same package they declare in
 * `metadata.boost-requires` (the same dependency rule project sync applies).
 * A named skill the package does not ship, and a declared dependency no skill
 * of the package satisfies, are warnings — the rest still publishes.
 *
 * @internal
 */
final readonly class UserScopeSkillSelector
{
    /**
     * @param  list<Skill>  $skills  every skill the package ships
     * @param  list<string>|null  $selection  the config entry, or null for none
     * @return array{skills: list<Skill>, warnings: list<string>}
     */
    public function select(array $skills, ?array $selection, string $packageName, string $configPath): array
    {
        if ($selection === null) {
            return ['skills' => $skills, 'warnings' => []];
        }

        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }

        $warnings = [];
        $selected = [];
        foreach ($selection as $name) {
            if (! isset($byName[$name])) {
                $warnings[] = sprintf('Unknown skill "%s" in %s for %s — %s does not ship it.', $name, $configPath, $packageName, $packageName);

                continue;
            }

            $selected[$name] = $byName[$name];
        }

        $rest = array_values(array_filter(
            $skills,
            static fn (Skill $skill): bool => ! isset($selected[$skill->name]),
        ));

        $resolved = (new SkillDependencyResolver())->resolve(
            array_values($selected),
            [$packageName => ['tagMismatch' => $rest, 'excluded' => []]],
        );

        foreach ($resolved['pulls'] as $pull) {
            $warnings[] = sprintf(
                'Published %s%s because %s requires it.',
                $pull['name'],
                UserScopeSkillRenamer::SUFFIX,
                $pull['requiredBy'],
            );
        }

        foreach ($resolved['warnings'] as $missing) {
            $warnings[] = sprintf(
                'Skill "%s" is required by %s, but %s does not ship it — published without it.',
                $missing['name'],
                implode(', ', $missing['dependents']),
                $packageName,
            );
        }

        return ['skills' => $resolved['skills'], 'warnings' => $warnings];
    }
}
