<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use RuntimeException;

/**
 * Two allowlisted vendors publish a subagent under the same `name`.
 *
 * Fatal rather than advisory, matching {@see CollidingSkillsException}: Claude
 * Code resolves a dispatch by name, so shipping both would make which one runs
 * depend on filesystem read order. A host `.ai/subagents/` file of the same name
 * resolves it (host always wins, and the shadow is reported); `--force` falls
 * back to vendor declaration order.
 *
 * @internal
 */
final class CollidingSubagentsException extends RuntimeException
{
    /**
     * @param  string  $name  The colliding subagent name.
     * @param  list<string>  $vendors  Vendors that all publish this name.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $vendors,
    ) {
        parent::__construct(sprintf(
            'Subagent "%s" is published by multiple vendors: %s. Host can override; vendor-vs-vendor collisions require --force.',
            $name,
            implode(', ', $vendors),
        ));
    }
}
