<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Symfony\Component\Console\Command\Command;

/**
 * Single source of truth for boost-core command instances.
 *
 * Used by both the standalone bin (`bin/boost`) and the Composer plugin
 * CommandProvider. Lives outside the Composer namespace so the standalone
 * bin can load it in end-user installs where composer/composer is not in
 * vendor/.
 */
final class CommandRegistry
{
    /**
     * @return array<Command>
     */
    public static function commands(): array
    {
        return [
            new InstallCommand(),
            new ScanCommand(),
            new SyncCommand(),
            new DoctorCommand(),
            new NewCommand(),
        ];
    }
}
