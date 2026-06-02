<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Discover skill tags: what tags installed vendor skills declare, which
 * skills the project's `withTags()` currently filters out, and the tags to
 * add to receive them.
 *
 * The same report appears as a section of `boost:doctor`; this command is
 * the focused, standalone view of it.
 *
 * @internal
 */
final class TagsCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly TagReporter $reporter = new TagReporter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:tags')
            ->setDescription('Discover skill tags — what installed skills declare, what is filtered out, and the tags to add to withTags().');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $io->title('boost-core tags');

        $config = $this->loadConfig($io, $projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $this->reporter->report($io, $config);

        return self::SUCCESS;
    }
}
