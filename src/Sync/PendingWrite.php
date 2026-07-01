<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Conventions\ManagedRegion;

/**
 * A file that an AgentTarget wants written, expressed in terms relative to
 * the project root. FileWriter resolves to an absolute path and performs
 * the write (or compares for --check mode).
 *
 * When `$managedRegion` is non-null, `$content` is treated as the body to
 * place BETWEEN the markers. FileWriter reads the existing file, applies
 * `ManagedRegion::render()`, and writes the merged result — preserving any
 * operator-authored content OUTSIDE the markers. This is how `CLAUDE.md`,
 * `AGENTS.md`, etc. coexist with operator-added sections like the Project
 * Conventions block.
 *
 * @internal
 */
final readonly class PendingWrite
{
    /**
     * @param  bool  $pruneLegacyFlatSibling  True only for a skill's ENTRY write (`<name>/SKILL.md`), where an older boost-core run may have left an obsolete flat `<name>.md` to delete. Asset and command writes must never set this — an asset path can also end in `/SKILL.md` (e.g. `examples/SKILL.md`), and pruning from it would delete a sibling asset.
     */
    public function __construct(
        public string $relativePath,
        public string $content,
        public ?ManagedRegion $managedRegion = null,
        public bool $pruneLegacyFlatSibling = false,
    ) {}
}
