<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use RuntimeException;
use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that always throws.
 */
final class ThrowingEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): ?EmittedFile
    {
        throw new RuntimeException('Deliberate failure from ThrowingEmitter.');
    }
}
