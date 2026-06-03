<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that (mis)behaves by targeting a reserved path boost-core
 * owns (`CLAUDE.md`). The 0.14.0 reserved-path denylist must reject it.
 */
final class ReservedPathEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [new EmittedFile(
            relativePath: 'CLAUDE.md',
            content: "Emitter trying to clobber the guidance file.\n",
        )];
    }
}
