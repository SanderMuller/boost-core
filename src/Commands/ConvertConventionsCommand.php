<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigWriteException;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One-shot migration: extract operator-filled YAML from CLAUDE.md's marker
 * region and write it into `boost.php` as `->withConventions([...])`. After
 * conversion, `boost.php` is the source of truth and CLAUDE.md is re-rendered
 * from it on every sync.
 *
 * Edge cases handled per spec §3.4: no CLAUDE.md, no marker region,
 * scaffold-only body, both-sources-present, YAML parse failure.
 *
 * @internal
 */
final class ConvertConventionsCommand extends BoostBaseCommand
{
    private const CLAUDE_MD = 'CLAUDE.md';

    protected function configure(): void
    {
        $this
            ->setName('boost:convert-conventions')
            // Legacy one-shot for the pre-0.12 marker → boost.php format. Hidden
            // from the 1.0 command list (still runnable for stragglers) — not part
            // of the committed CLI contract.
            ->setHidden(true)
            ->setDescription("Migrate Project Conventions YAML from CLAUDE.md into boost.php's ->withConventions([...]) chain.")
            ->addWorkingDirOption()
            ->addConfigOption()
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the diff against boost.php without writing.')
            ->addOption('keep-block', null, InputOption::VALUE_NONE, 'Leave the CLAUDE.md marker region untouched after writing into boost.php.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);
        $configOverride = $this->configFileOption($input);

        $config = $this->loadConfig($io, $projectRoot, $configOverride);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        // Resolved location (root or .config/boost.php) — write back where the
        // config actually lives. Safe post-load.
        $configPath = BoostConfigPath::resolve($projectRoot, $configOverride)->path;
        $claudeMdPath = $projectRoot . '/' . self::CLAUDE_MD;

        // Edge: no CLAUDE.md
        if (! is_file($claudeMdPath)) {
            $io->writeln('no CLAUDE.md found — nothing to convert. Add ->withConventions([...]) to boost.php directly if you want to declare slot values for an allowlisted vendor schema.');

            return self::SUCCESS;
        }

        $claudeMd = (string) file_get_contents($claudeMdPath);
        $emitter = new ConventionsBlockEmitter();

        // Edge: no marker region in CLAUDE.md
        if (! str_contains($claudeMd, ConventionsBlockEmitter::START_MARKER)) {
            $io->writeln('no Project Conventions marker region found in CLAUDE.md — nothing to convert. If you intended to migrate from a 0.8.x project, the markers should already exist; run `vendor/bin/boost sync` first to scaffold the region, OR add ->withConventions([...]) to boost.php directly.');

            return self::SUCCESS;
        }

        $extracted = $emitter->extract($claudeMd);
        if ($extracted === null) {
            $io->error('Could not extract Project Conventions body from CLAUDE.md (marker region appears malformed).');

            return self::FAILURE;
        }

        $trimmedBody = trim($extracted);

        // Edge: empty / scaffold-only body
        if ($trimmedBody === '' || $this->isScaffoldOnlyBody($trimmedBody)) {
            $io->writeln('Project Conventions marker region in CLAUDE.md is empty (no operator-filled values to migrate). The region will be re-rendered from boost.php on next sync. Add slot values to boost.php directly via ->withConventions([...]).');

            return self::SUCCESS;
        }

        // Parse the YAML body
        try {
            $parsed = Yaml::parse($trimmedBody);
        } catch (ParseException $parseException) {
            $io->error('YAML parse failure in CLAUDE.md marker body: ' . $parseException->getMessage());

            return self::FAILURE;
        }

        if (! is_array($parsed)) {
            $io->error('Project Conventions YAML must decode to a mapping at the root.');

            return self::FAILURE;
        }

        // Drop schema-version — re-injected synthetically by the engine.
        unset($parsed['schema-version']);

        // Edge: boost.php already has ->withConventions(...)
        if ($config->conventions !== []) {
            $io->error('boost.php already declares ->withConventions([...]) but CLAUDE.md also has a filled Project Conventions block. Refusing to overwrite. Reconcile manually: either delete the boost.php chain (then re-run convert to use CLAUDE.md as source) OR delete the CLAUDE.md marker body (boost.php remains source).');

            return self::FAILURE;
        }

        // Write via BoostConfigWriter
        $dryRun = (bool) $input->getOption('dry-run');
        $writer = new BoostConfigWriter();
        try {
            /** @var array<string, mixed> $values */
            $values = $parsed;
            $newSource = $writer->writeConventions($configPath, $values, dryRun: $dryRun);
        } catch (BoostConfigWriteException $boostConfigWriteException) {
            $io->error('Could not update boost.php: ' . $boostConfigWriteException->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $io->section('Preview (dry-run — no files modified)');
            $output->writeln($newSource);
            $io->note('Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $io->success(sprintf(
            "Migrated %d slot group(s) from CLAUDE.md into boost.php's ->withConventions([...]) chain.",
            count($values),
        ));

        // Optionally clear the CLAUDE.md marker body so the next sync re-renders cleanly from boost.php
        if (! (bool) $input->getOption('keep-block')) {
            $clearedClaudeMd = $this->clearMarkerBody($claudeMd);
            file_put_contents($claudeMdPath, $clearedClaudeMd);
            $io->writeln('Cleared the CLAUDE.md marker body — next `vendor/bin/boost sync` re-renders from boost.php.');
        } else {
            $io->writeln('--keep-block set: CLAUDE.md marker body left intact. Next sync will detect two-source conflict and block the write until you reconcile.');
        }

        $io->writeln('Next steps:');
        $io->writeln('  1. Review the boost.php diff to confirm values match.');
        $io->writeln('  2. Run `vendor/bin/boost sync` to re-render CLAUDE.md from boost.php.');
        $io->writeln('  3. Run `vendor/bin/boost validate` then commit boost.php + CLAUDE.md together as a single "Migrate Project Conventions to boost.php" change. CLAUDE.md stays tracked — operator-authored content outside the conventions markers (custom H1, intro prose) is preserved across sync.');

        return self::SUCCESS;
    }

    /**
     * Detects a scaffold-only body — `schema-version: N` plus comment lines.
     * No real operator values.
     */
    private function isScaffoldOnlyBody(string $trimmedBody): bool
    {
        try {
            $parsed = Yaml::parse($trimmedBody);
        } catch (ParseException) {
            return false;
        }

        if (! is_array($parsed)) {
            return false;
        }

        foreach (array_keys($parsed) as $key) {
            if ($key !== 'schema-version') {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace the body between `<!-- boost-core:conventions:start -->` and
     * `<!-- boost-core:conventions:end -->` with the scaffold-only stub so
     * the next sync re-renders cleanly from boost.php.
     */
    private function clearMarkerBody(string $claudeMd): string
    {
        $startPos = strpos($claudeMd, ConventionsBlockEmitter::START_MARKER);
        if ($startPos === false) {
            return $claudeMd;
        }

        $endPos = strpos($claudeMd, ConventionsBlockEmitter::END_MARKER, $startPos);
        if ($endPos === false) {
            return $claudeMd;
        }

        $startLineEnd = strpos($claudeMd, "\n", $startPos);
        if ($startLineEnd === false || $startLineEnd >= $endPos) {
            return $claudeMd;
        }

        // Empty between markers — next sync re-renders from boost.php
        $before = substr($claudeMd, 0, $startLineEnd + 1);
        $after = substr($claudeMd, $endPos);

        return $before . $after;
    }
}
