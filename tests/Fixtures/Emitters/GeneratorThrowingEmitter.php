<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use RuntimeException;
use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter whose emit() is a GENERATOR that throws mid-iteration —
 * exercises the 0.21.0 contract's lazy-failure path: the exception surfaces
 * while the engine iterates the result, not at the emit() call, and must still
 * be recorded as `errored` rather than aborting the sync.
 */
final class GeneratorThrowingEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        yield new EmittedFile(relativePath: '.gen/first.txt', content: "first\n");

        throw new RuntimeException('Deliberate failure mid-generator.');
    }
}
