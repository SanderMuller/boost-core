<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use RuntimeException;

/**
 * ONE package ships two subagent files declaring the same `name`.
 *
 * Separate from {@see CollidingSubagentsException} because the remedy is
 * different and so is the audience: a cross-vendor collision is the consumer's
 * to resolve (host override, or `--force`), while this one is an authoring
 * mistake only the package can fix. Folding it into the cross-vendor message
 * would name the same package twice and read as an engine bug.
 *
 * @internal
 */
final class DuplicateSubagentNameException extends RuntimeException
{
    /**
     * @param  list<string>  $paths  The two source files claiming the name.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $vendor,
        public readonly array $paths,
    ) {
        parent::__construct(sprintf(
            'Package %s ships two subagents named "%s" (%s). A subagent\'s identity is its `name` frontmatter, not its filename, so one of them must be renamed.',
            $vendor,
            $name,
            implode(' and ', $paths),
        ));
    }
}
