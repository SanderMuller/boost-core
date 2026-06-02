<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Symfony\Component\Console\Command\Command;

/**
 * Single source of truth for boost-core command instances.
 *
 * Feeds the standalone `bin/boost`. Lives outside the Composer namespace
 * so it loads in end-user installs where composer/composer is not in
 * vendor/.
 *
 * @internal
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
            new TagsCommand(),
            new WhereCommand(),
            new NewCommand(),
            new ValidateCommand(),
            new SlotsCommand(),
            new PathsCommand(),
            new ConvertConventionsCommand(),
        ];
    }
}
