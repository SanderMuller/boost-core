<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that always returns null (skipped).
 */
final class SkippingEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [];
    }
}
