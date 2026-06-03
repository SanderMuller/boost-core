<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that changes its output path by CASE ONLY depending on a
 * marker file — `.Dummy/output.txt` on the first sync, `.dummy/output.txt`
 * after the marker exists. Exercises the case-insensitive reap guard: a
 * case-only rename must not reap the just-written live file.
 */
final class CaseRenameEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        $renamed = is_file($ctx->projectRoot . '/.rename-marker');

        return [new EmittedFile(
            relativePath: $renamed ? '.dummy/output.txt' : '.Dummy/output.txt',
            content: "Case-rename emitter output.\n",
        )];
    }
}
