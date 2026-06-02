<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * @internal
 */
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
     *   silent override in `boost where` / `boost sync` output.
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

        // Impose a deterministic order on the OUTPUT so emitted guidance is
        // byte-identical across platforms (the vendor-scan + injected-merge order is
        // otherwise filesystem-derived — APFS vs ext4 — driving CI auto-fix loops).
        // Output-only: the resolution loop above (which picks the --force winner +
        // records shadows) is untouched, so this changes ordering, never
        // precedence/collision semantics.
        //
        // Host-authored guidelines come FIRST and keep their existing order — they
        // already arrive in the loader's deterministic sortByName order, so this
        // sort must NOT re-key them (sorting host by sourcePath would diverge from
        // the loader's filename order under nested dirs). PHP 8 usort is
        // stable, so returning 0 for any host pair preserves that loader order; only
        // the VENDOR/injected portion (the actual non-determinism) is sorted, by
        // (sourceVendor, sourcePath) — both stable, not FS-derived; this also
        // normalises injected guidelines, which bypass the loader's sort.
        $out = array_values($resolved);
        usort($out, static function (Guideline $a, Guideline $b): int {
            if ($a->isHostAuthored() || $b->isHostAuthored()) {
                // host (0) before vendor (1); host↔host → 0 → stable keeps loader order.
                return ($a->isHostAuthored() ? 0 : 1) <=> ($b->isHostAuthored() ? 0 : 1);
            }

            return [$a->sourceVendor ?? '', $a->sourcePath] <=> [$b->sourceVendor ?? '', $b->sourcePath];
        });

        return $out;
    }
}
