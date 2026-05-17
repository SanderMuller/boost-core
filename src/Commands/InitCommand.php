<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rector-style init: generate a starter boost.php at the project root.
 *
 * Refuses to overwrite an existing file unless --force is passed. The
 * generated config has empty agents + allowlist — user is expected to
 * run `boost:install` next for the interactive picker.
 */
final class InitCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:init')
            ->setDescription('Generate a starter boost.php at the project root.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite an existing boost.php.',
            );
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $force = (bool) $input->getOption('force');
        $target = $projectRoot . '/boost.php';

        if (is_file($target) && ! $force) {
            $io->error(sprintf(
                'boost.php already exists at %s. Re-run with --force to overwrite, or edit the existing file.',
                $target,
            ));

            return self::FAILURE;
        }

        $contents = $this->starterContents();

        if (file_put_contents($target, $contents) === false) {
            $io->error(sprintf('Failed to write boost.php at %s.', $target));

            return self::FAILURE;
        }

        $io->success(sprintf('Generated %s', $target));
        $io->writeln('Next: run <info>composer boost:install</info> to pick agents and allowlist vendor packages.');

        return self::SUCCESS;
    }

    private function starterContents(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use SanderMuller\BoostCore\Config\BoostConfig;
            use SanderMuller\BoostCore\Enums\Agent;

            /**
             * boost-core configuration.
             *
             * Run `composer boost:install` to populate agents + allowlist interactively,
             * or hand-edit this file. After changes run `composer boost:sync`.
             *
             * Docs: https://github.com/sandermuller/boost-core
             */
            return BoostConfig::configure()
                // Which AI agents to publish skills/guidelines to. Add Agent enum cases.
                // Example: Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT
                ->withAgents([])

                // Vendor packages allowed to publish skills/guidelines into your project.
                // Each entry is a Composer package name. Add via `composer boost:scan` or hand-edit.
                ->withAllowedVendors([])

                // Optionally disable specific FileEmitter implementations by FQCN.
                ->withDisabledEmitters([])

                // Source paths (relative or absolute). Defaults shown — uncomment to override.
                // ->withSkillsPath(__DIR__ . '/.ai/skills')
                // ->withGuidelinesPath(__DIR__ . '/.ai/guidelines')
            ;

            PHP;
    }
}
