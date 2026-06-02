<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ManagedPathsResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final class PathsCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:paths')
            ->setDescription('List path globs boost-core manages (vendor-skill-reachable).')
            ->addWorkingDirOption()
            ->addConfigOption()
            ->addOption('managed', null, InputOption::VALUE_NONE, 'List the managed-path globs (default).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope for tooling.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $config = $this->loadConfig($io, $projectRoot, $this->configFileOption($input));
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $patterns = ManagedPathsResolver::default()->patterns($config);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['paths' => $patterns], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($patterns as $pattern) {
            $output->writeln($pattern);
        }

        return self::SUCCESS;
    }
}
