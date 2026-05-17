<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class SyncCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:sync')
            ->setDescription('Generate agent-specific skill and guideline files from .ai/ + allowlisted vendors.')
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Report drift without writing. Non-zero exit if any file would change.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Resolve vendor-vs-vendor skill collisions silently by declaration order.',
            );
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $checkOnly = (bool) $input->getOption('check');
        $force = (bool) $input->getOption('force');

        try {
            $result = SyncEngine::default()->sync($projectRoot, $checkOnly, $force);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error('boost:sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return $this->report($io, $result, $checkOnly);
    }

    private function report(SymfonyStyle $io, SyncResult $result, bool $checkOnly): int
    {
        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }

            return self::FAILURE;
        }

        if ($checkOnly && $result->hasDrift()) {
            $io->warning(sprintf(
                'Drift detected: %d file(s) would change.',
                $result->countByAction(WriteAction::WOULD_WRITE),
            ));

            foreach ($result->writes as $write) {
                if ($write->action === WriteAction::WOULD_WRITE) {
                    $io->writeln('  ~ ' . $write->relativePath);
                }
            }

            return self::FAILURE;
        }

        $wrote = $result->countByAction(WriteAction::WROTE);
        $unchanged = $result->countByAction(WriteAction::UNCHANGED);
        $emittersWrote = $result->countEmittersByAction(EmitterAction::WROTE);
        $emittersSkipped = $result->countEmittersByAction(EmitterAction::SKIPPED);

        $emitterSummary = '';
        if ($result->emitters !== []) {
            $emitterSummary = sprintf(
                ' emitters(wrote=%d, skipped=%d)',
                $emittersWrote,
                $emittersSkipped,
            );
        }

        if ($checkOnly) {
            $io->success(sprintf('No drift. %d file(s) unchanged.%s', $unchanged, $emitterSummary));

            return self::SUCCESS;
        }

        $io->success(sprintf('Sync done. wrote=%d, unchanged=%d.%s', $wrote, $unchanged, $emitterSummary));

        return self::SUCCESS;
    }
}
