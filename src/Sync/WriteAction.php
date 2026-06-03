<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * @api Stable as of 1.0 — the per-file action on a {@see WrittenFile} in an
 * `@api` {@see SyncResult}. New cases may be added (additive); existing backing
 * values won't change.
 */
enum WriteAction: string
{
    case WROTE = 'wrote';
    case UNCHANGED = 'unchanged';
    case WOULD_WRITE = 'would-write';

    /** A stale agent-dir skill directory removed because its skill was tag-filtered out. */
    case DELETED = 'deleted';

    /** Check mode: a stale skill directory that a real sync would delete. */
    case WOULD_DELETE = 'would-delete';

    /**
     * Write skipped because a path segment is a user-placed symbolic link.
     * Following the link would write into the link target — typically
     * `.ai/skills/<name>/SKILL.md` — overwriting the source the symlink
     * was meant to read from. Honors the "live symlinks owned by consumer"
     * contract documented in `SyncEngine::pruneDeadSymlinks()`.
     */
    case SKIPPED_SYMLINK = 'skipped-symlink';
}
