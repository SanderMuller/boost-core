<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter. Always emits a fixed file at `.dummy/output.txt`.
 */
final class DummyEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [new EmittedFile(
            relativePath: '.dummy/output.txt',
            content: "Dummy emitter output for project root: {$ctx->projectRoot}\n",
        )];
    }
}
