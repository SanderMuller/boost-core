<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that emits MORE THAN ONE file in a single run — exercises
 * the 0.21.0 `emit(): iterable<EmittedFile>` contract.
 */
final class MultiFileEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [
            new EmittedFile(relativePath: '.multi/a.txt', content: "alpha\n"),
            new EmittedFile(relativePath: '.multi/b.txt', content: "beta\n"),
        ];
    }
}
