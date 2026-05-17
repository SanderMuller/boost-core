<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

enum WriteAction: string
{
    case WROTE = 'wrote';
    case UNCHANGED = 'unchanged';
    case WOULD_WRITE = 'would-write';
}
