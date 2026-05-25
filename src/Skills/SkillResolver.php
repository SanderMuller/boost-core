<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Resolves a set of skills from multiple sources into a deduplicated list.
 *
 * Collision precedence (per architecture plan):
 *
 * 1. Host `.ai/` always wins. Vendor skills sharing a name are silently
 *    overridden — this is the canonical override mechanism.
 * 2. Allowlisted vendors compete in `composer.json` `require` declaration
 *    order (caller-provided). First vendor wins.
 * 3. Vendor-vs-vendor collisions on the SAME name trigger CollidingSkillsException
 *    unless `$force = true`. Force mode falls back to declaration order.
 */
final class SkillResolver
{
    /**
     * @param  iterable<Skill>  $host  Host-authored skills from .ai/skills/
     * @param  array<string, iterable<Skill>>  $vendors  Map of vendor-name → skills.
     *                                                   Iteration order = precedence order.
     * @param  list<array{skill: string, shadowedVendor: string}>  $shadows  Out-param: each host-vs-vendor shadow event recorded as `{skill, shadowedVendor}` so callers can surface the silent override in sync output.
     * @return list<Skill>
     *
     * @throws CollidingSkillsException
     */
    public function resolve(iterable $host, array $vendors, bool $force = false, array &$shadows = []): array
    {
        $resolved = [];
        $vendorsByName = [];

        foreach ($host as $skill) {
            $resolved[$skill->name] = $skill;
        }

        foreach ($vendors as $vendor => $vendorSkills) {
            foreach ($vendorSkills as $skill) {
                $name = $skill->name;

                if (isset($resolved[$name]) && $resolved[$name]->isHostAuthored()) {
                    $shadows[] = ['skill' => $name, 'shadowedVendor' => (string) $vendor];

                    continue;
                }

                if (isset($vendorsByName[$name]) && ! $force) {
                    throw new CollidingSkillsException(
                        name: $name,
                        vendors: [...$vendorsByName[$name], (string) $vendor],
                    );
                }

                if (! isset($resolved[$name])) {
                    $resolved[$name] = $skill;
                    $vendorsByName[$name] = [(string) $vendor];
                } else {
                    $vendorsByName[$name][] = (string) $vendor;
                }
            }
        }

        return array_values($resolved);
    }
}
