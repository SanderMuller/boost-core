<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Fixtures\Emitters;

use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Test-only emitter that targets an agent skill root (`.claude/skills/...`).
 * That surface is boost-managed for EVERY agent regardless of which agents are
 * active, so 0.14.0 rejects it even in a project with no agents configured.
 */
final class InactiveAgentRootEmitter implements FileEmitter
{
    public function emit(SyncContext $ctx): iterable
    {
        return [new EmittedFile(
            relativePath: '.claude/skills/injected/SKILL.md',
            content: "Emitter writing into an agent skill root.\n",
        )];
    }
}
