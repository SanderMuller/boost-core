<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Resolves host + vendor subagents into one deduplicated list.
 *
 * Precedence mirrors {@see SkillResolver} exactly — the rule operators already
 * know, applied to a second artefact:
 *
 * 1. Host `.ai/subagents/` always wins. The shadow is RECORDED, not silent, so
 *    `boost sync` and `boost doctor` can report it.
 * 2. Allowlisted vendors compete in declaration order. First vendor wins.
 * 3. Vendor-vs-vendor on one name throws {@see CollidingSubagentsException}
 *    unless `$force`.
 *
 * @internal
 */
final class SubagentResolver
{
    /**
     * @param  iterable<Subagent>  $host  Host-authored subagents from `.ai/subagents/`.
     * @param  array<string, iterable<Subagent>>  $vendors  vendor-name => subagents. Iteration order = precedence order.
     * @param  list<array{subagent: string, shadowedVendor: string}>  $shadows  Out-param: each host-vs-vendor shadow event.
     * @param  list<string>  $duplicateWarnings  Out-param: two HOST files declaring one name.
     * @return list<Subagent>
     *
     * @throws CollidingSubagentsException  Two vendors publish one name and `$force` is false.
     * @throws DuplicateSubagentNameException  One package ships two files claiming one name.
     */
    public function resolve(iterable $host, array $vendors, bool $force = false, array &$shadows = [], array &$duplicateWarnings = []): array
    {
        $resolved = [];
        $vendorsByName = [];

        foreach ($host as $subagent) {
            $name = $subagent->name;
            // Unlike skills — one directory per name, so a duplicate is
            // impossible — subagents are flat files whose identity lives in
            // frontmatter, so two host files can claim one name. Keep the
            // first (the loader sorts by path, so the winner is stable) and
            // say which file lost, rather than dropping it silently.
            if (isset($resolved[$name])) {
                $duplicateWarnings[] = sprintf(
                    'host subagent `%s` is declared by two files; `%s` wins and `%s` is ignored. Rename one.',
                    $name,
                    $resolved[$name]->sourcePath,
                    $subagent->sourcePath,
                );

                continue;
            }

            $resolved[$name] = $subagent;
        }

        foreach ($vendors as $vendor => $vendorSubagents) {
            /** @var array<string, Subagent> $seenInVendor */
            $seenInVendor = [];
            foreach ($vendorSubagents as $subagent) {
                $name = $subagent->name;

                // Two files inside ONE package. {@see SubagentPipeline} checks
                // this earlier, on the unfiltered set, so a tag filter cannot
                // hide the mistake; this guard keeps the resolver correct in
                // isolation, and stops the cross-vendor branch below from
                // naming one package twice.
                if (isset($seenInVendor[$name])) {
                    throw new DuplicateSubagentNameException(
                        name: $name,
                        vendor: (string) $vendor,
                        paths: [$seenInVendor[$name]->sourcePath, $subagent->sourcePath],
                    );
                }

                $seenInVendor[$name] = $subagent;

                if (isset($resolved[$name]) && $resolved[$name]->isHostAuthored()) {
                    $shadows[] = ['subagent' => $name, 'shadowedVendor' => (string) $vendor];

                    continue;
                }

                if (isset($vendorsByName[$name]) && ! $force) {
                    throw new CollidingSubagentsException(
                        name: $name,
                        vendors: [...$vendorsByName[$name], (string) $vendor],
                    );
                }

                if (! isset($resolved[$name])) {
                    $resolved[$name] = $subagent;
                    $vendorsByName[$name] = [(string) $vendor];
                } else {
                    $vendorsByName[$name][] = (string) $vendor;
                }
            }
        }

        return array_values($resolved);
    }
}
