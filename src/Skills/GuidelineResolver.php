<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final class GuidelineResolver
{
    /**
     * @param  iterable<Guideline>  $host
     * @param  array<string, iterable<Guideline>>  $vendors  Already tag-filtered
     *   by the caller — only tag-eligible vendor guidelines reach here, so a
     *   host guideline shadowing a tag-FILTERED-OUT vendor copy is never
     *   recorded (the filtered copy isn't in this map). That satisfies the
     *   shadow tag-eligibility rule by construction.
     * @param  list<array{guideline: string, shadowedVendor: string}>  $shadows
     *   Out-param: each host-vs-vendor shadow event, so callers can surface the
     *   silent override in `boost where` / `boost sync` output. Mirrors
     *   `SkillResolver::resolve()`.
     * @return list<Guideline>
     *
     * @throws CollidingSkillsException
     */
    public function resolve(iterable $host, array $vendors, bool $force = false, array &$shadows = []): array
    {
        $resolved = [];
        $vendorsByName = [];

        foreach ($host as $guideline) {
            $resolved[$guideline->name] = $guideline;
        }

        foreach ($vendors as $vendor => $vendorGuidelines) {
            foreach ($vendorGuidelines as $guideline) {
                $name = $guideline->name;

                if (isset($resolved[$name]) && $resolved[$name]->isHostAuthored()) {
                    $shadows[] = ['guideline' => $name, 'shadowedVendor' => (string) $vendor];

                    continue;
                }

                if (isset($vendorsByName[$name]) && ! $force) {
                    throw new CollidingSkillsException(
                        name: $name,
                        vendors: [...$vendorsByName[$name], (string) $vendor],
                    );
                }

                if (! isset($resolved[$name])) {
                    $resolved[$name] = $guideline;
                    $vendorsByName[$name] = [(string) $vendor];
                } else {
                    $vendorsByName[$name][] = (string) $vendor;
                }
            }
        }

        return array_values($resolved);
    }
}
