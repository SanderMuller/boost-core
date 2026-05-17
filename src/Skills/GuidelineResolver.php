<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final class GuidelineResolver
{
    /**
     * @param  iterable<Guideline>  $host
     * @param  array<string, iterable<Guideline>>  $vendors
     * @return list<Guideline>
     *
     * @throws CollidingSkillsException
     */
    public function resolve(iterable $host, array $vendors, bool $force = false): array
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
