<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared base for boost-family Composer commands.
 *
 * Provides:
 * - The `--working-dir` / `-d` option declaration
 * - Project-root resolution from that option (or current working directory)
 *
 * Both boost-core's own commands and package-boost-php's commands extend this.
 */
abstract class BoostBaseCommand extends BaseCommand
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
}
