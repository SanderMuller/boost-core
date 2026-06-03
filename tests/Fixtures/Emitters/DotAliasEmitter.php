<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that emits to a `.`-aliased path (`./.dummy/output.txt`).
 * 0.14.0 canonicalizes to `.dummy/output.txt` so the manifest records and
 * matches one stable spelling — a re-sync must NOT reap the live file.
 */
final class DotAliasEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [new EmittedFile(
            relativePath: './.dummy/output.txt',
            content: "Dot-aliased emitter output.\n",
        )];
    }
}
