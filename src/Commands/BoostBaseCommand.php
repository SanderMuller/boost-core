<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared base for boost-family commands.
 *
 * Extends Symfony's Command directly so commands load in the standalone
 * `bin/boost` — including end-user installs where composer/composer is not
 * in vendor/.
 *
 * @internal
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

    protected function addConfigOption(): static
    {
        $this->addOption(
            'config',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to a boost.php config (a relative path resolves against the project root). Overrides auto-discovery of root vs .config/boost.php.',
        );

        return $this;
    }

    /**
     * The `--config` override value, or null when unset/empty. A relative path is
     * resolved against the project root by {@see BoostConfigPath}.
     */
    protected function configFileOption(InputInterface $input): ?string
    {
        if (! $input->hasOption('config')) {
            return null;
        }

        $value = $input->getOption('config');

        return is_string($value) && $value !== '' ? $value : null;
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
     * Guard for commands whose flow needs an interactive terminal (the
     * `multiselect` pickers). Returns true when interactive; otherwise prints
     * `$guidance` and returns false so the caller can fail fast with a clear
     * message instead of hanging on a prompt in CI / under `--no-interaction`.
     */
    protected function isInteractiveOrExplain(InputInterface $input, SymfonyStyle $io, string $guidance): bool
    {
        if ($input->isInteractive()) {
            return true;
        }

        $io->error($guidance);

        return false;
    }

    /**
     * Load and build the project's `boost.php`. Renders the failure to `$io`
     * and returns null on a missing or broken config — callers return
     * `self::FAILURE` on null.
     */
    protected function loadConfig(SymfonyStyle $io, string $projectRoot, ?string $configFile = null): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader())->load($projectRoot, $configFile);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return null;
        } catch (Throwable $e) {
            $io->error('boost.php failed to load: ' . $e->getMessage());

            return null;
        }
    }
}
