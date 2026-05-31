<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that tries to dodge the reserved-path denylist with a
 * `.`-segment alias (`./CLAUDE.md`) that resolves to the real `CLAUDE.md`.
 * 0.14.0 canonicalizes the path before the denylist check, so it is rejected.
 */
final class DotAliasReservedEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): EmittedFile
    {
        return new EmittedFile(
            relativePath: './CLAUDE.md',
            content: "Alias clobber attempt.\n",
        );
    }
}
