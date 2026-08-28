<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Why a resolved skill did or did not reach an agent directory.
 *
 * The vocabulary is shared so two entry points cannot describe one skill
 * differently — a wrapper's `where` and boost-core's must agree on whether a
 * skill shipped, and on why it did not. The PRESENTATION is not shared:
 * colours, wording and column layout stay with each CLI, because boost-core
 * has no business freezing another package's output.
 *
 * @api Stable as of 1.8. New cases may be added in a MINOR, so match
 * exhaustively at your own risk — prefer a `default`.
 */
enum SkillShipmentStatus: string
{
    /** Emitted, or already identical on disk. */
    case SHIPPED = 'shipped';

    /** Lost to a host skill of the same name. {@see SkillShipmentIndex::shadowedVendorFor()} names the vendor. */
    case SHADOWED = 'shadowed';

    /** Tagged, and the project's `withTags()` does not declare its tags. Adding a tag would ship it. */
    case TAG_FILTERED = 'tag-filtered';

    /** Untagged and still absent — `withExcludedSkills`, a render failure, or a source that dropped out. Tag advice would mislead. */
    case EXCLUDED = 'excluded';
}
