<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

enum WriteAction: string
{
    case WROTE = 'wrote';
    case UNCHANGED = 'unchanged';
    case WOULD_WRITE = 'would-write';

    /** A stale agent-dir skill directory removed because its skill was tag-filtered out. */
    case DELETED = 'deleted';

    /** Check mode: a stale skill directory that a real sync would delete. */
    case WOULD_DELETE = 'would-delete';
}
