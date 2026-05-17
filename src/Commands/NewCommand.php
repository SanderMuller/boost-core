<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigLoader;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Scaffold a new skill or guideline markdown file with frontmatter template.
 *
 * Examples:
 *   composer boost:new skill foo-bar
 *   composer boost:new guideline conventions
 */
final class NewCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly BoostConfigLoader $loader = new BoostConfigLoader(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:new')
            ->setDescription('Scaffold a new skill or guideline markdown file with frontmatter template.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                'Either `skill` or `guideline`.',
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Slug for the file (no extension). Example: `release-notes`.',
            )
            ->addOption(
                'description',
                null,
                InputOption::VALUE_REQUIRED,
                'Short description for frontmatter.',
                '',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite existing file.',
            );
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = $input->getArgument('type');
        $name = $input->getArgument('name');

        if (! is_string($type) || ! in_array($type, ['skill', 'guideline'], true)) {
            $io->error('Type must be `skill` or `guideline`.');

            return self::FAILURE;
        }

        if (! is_string($name) || $name === '') {
            $io->error('Name is required.');

            return self::FAILURE;
        }

        $description = $input->getOption('description');
        if (! is_string($description)) {
            $description = '';
        }

        $force = (bool) $input->getOption('force');
        $projectRoot = $this->resolveProjectRoot($input);

        try {
            $config = $this->loader->load($projectRoot);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $targetDir = $type === 'skill' ? $config->skillsPath : $config->guidelinesPath;
        $targetFile = $targetDir . '/' . $name . '.md';

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0o755, recursive: true) && ! is_dir($targetDir)) {
            $io->error(sprintf('Failed to create directory: %s', $targetDir));

            return self::FAILURE;
        }

        if (is_file($targetFile) && ! $force) {
            $io->error(sprintf('%s already exists. Re-run with --force to overwrite.', $targetFile));

            return self::FAILURE;
        }

        if (file_put_contents($targetFile, $this->template($name, $description, $type)) === false) {
            $io->error(sprintf('Failed to write %s.', $targetFile));

            return self::FAILURE;
        }

        $io->success(sprintf('Created %s', $targetFile));
        $io->writeln('Next: edit the file, then run <info>composer boost:sync</info> to publish.');

        return self::SUCCESS;
    }

    private function template(string $name, string $description, string $type): string
    {
        $descLine = $description !== '' ? $description : sprintf('TODO: describe this %s.', $type);

        return <<<MD
            ---
            name: {$name}
            description: {$descLine}
            ---

            # {$name}

            TODO: body content.

            MD;
    }
}
