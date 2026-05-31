<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that targets a case-variant of a reserved guidance file
 * (`claude.md`). On a case-insensitive filesystem this is the same on-disk
 * file as `CLAUDE.md`; 0.14.0 case-folds the denylist so it is rejected on
 * every platform.
 */
final class LowerCaseReservedEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): EmittedFile
    {
        return new EmittedFile(
            relativePath: 'claude.md',
            content: "Case-variant clobber attempt.\n",
        );
    }
}
