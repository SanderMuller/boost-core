<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared base for boost-family commands.
 *
 * Extends Symfony's Command directly (not Composer\Command\BaseCommand) so
 * commands can be loaded in standalone-bin contexts where composer/composer
 * is not in vendor/. The Composer plugin path adds them via CommandProvider;
 * Composer's Application accepts plain Symfony commands.
 */
abstract class BoostBaseCommand extends Command
{
    protected function addWorkingDirOption(): static
    {
        $this->addOption(
            'working-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Project root. Defaults to current working directory.',
        );

        return $this;
    }

    protected function resolveProjectRoot(InputInterface $input): string
    {
        $workingDir = $input->getOption('working-dir');
        if (is_string($workingDir)) {
            return rtrim($workingDir, '/');
        }

        $cwd = getcwd();

        return rtrim($cwd === false ? '.' : $cwd, '/');
    }

    /**
     * Load and build the project's `boost.php`. Renders the failure to `$io`
     * and returns null on a missing or broken config — callers return
     * `self::FAILURE` on null.
     */
    protected function loadConfig(SymfonyStyle $io, string $projectRoot): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader())->load($projectRoot);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return null;
        } catch (Throwable $e) {
            $io->error('boost.php failed to load: ' . $e->getMessage());

            return null;
        }
    }
}
