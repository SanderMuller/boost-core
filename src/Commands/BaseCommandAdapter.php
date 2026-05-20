<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\Command\BaseCommand;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wraps a plain Symfony Command so it satisfies Composer's CommandProvider
 * capability, which runtime-checks `instanceof Composer\Command\BaseCommand`
 * and rejects anything else.
 *
 * This file is only autoloaded when something references it — which only
 * happens via `BoostCoreCommandProvider::getCommands()` inside an actual
 * Composer plugin invocation, where `Composer\Command\BaseCommand` is
 * always present. The standalone `bin/boost` path never touches the
 * provider or this adapter, so end-user installs without
 * `composer/composer` in vendor/ stay safe.
 */
final class BaseCommandAdapter extends BaseCommand
{
    public function __construct(private readonly Command $inner)
    {
        parent::__construct($inner->getName());
        $this->setDescription($inner->getDescription());
        $this->setHelp($inner->getHelp());
        $this->setDefinition($inner->getDefinition());
        if ($inner->isHidden()) {
            $this->setHidden(true);
        }
    }

    /**
     * Invoke the inner command's `execute()` directly via reflection.
     *
     * Why not `$this->inner->run($input, $output)`: the outer adapter has
     * already merged Composer's Application definition into its own and
     * bound $input against that — so $input legitimately carries Composer
     * global options like `--no-interaction`. Going through inner->run()
     * would call `mergeApplicationDefinition()` against the inner command's
     * (empty) application and rebind, rejecting any global option the
     * inner didn't declare.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ReflectionMethod($this->inner, 'execute'))
            ->invoke($this->inner, $input, $output);

        assert(is_int($result), 'Symfony Command::execute() contract requires int return.');

        return $result;
    }
}
