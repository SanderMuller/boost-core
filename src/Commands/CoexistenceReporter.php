<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncManifest;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports the laravel/boost ↔ boost-core coexistence state in `boost doctor`.
 *
 * The engine stays tool-agnostic; this ADVISORY-layer reporter is the only place
 * that names laravel/boost and recognizes its `<laravel-boost-guidelines>` marker.
 * It classifies each guidance file independently — boost-owned vs foreign-seeded —
 * and offers the wrapper's reconcile when a file was seeded by another tool.
 *
 * @internal
 */
final class CoexistenceReporter
{
    /**
     * laravel/boost wraps its guidelines in this marker (it writes a preserve-region
     * block). boost-core is markerless + wholesale and never emits it, so the marker's
     * presence in a NON-boost-owned guidance file is a precise foreign-writer signal —
     * one that works with or without a boost manifest (the primary just-installed case).
     */
    private const LARAVEL_BOOST_MARKER = '<laravel-boost-guidelines>';

    private const WRAPPER_PACKAGE = 'sandermuller/project-boost-laravel';

    public function report(SymfonyStyle $io, string $projectRoot, InstalledPackages $packages, bool $inConfigDir): void
    {
        if (! $packages->has('laravel/boost')) {
            return; // not a coexistence project — silent
        }

        $io->section('laravel/boost coexistence');

        if (! $packages->has(self::WRAPPER_PACKAGE)) {
            $io->warning(
                'laravel/boost is installed, but the project-boost-laravel wrapper is NOT. '
                . 'Without it, boost-core wholesale-owns the guidance files (CLAUDE.md/AGENTS.md/…) and a '
                . "sync overwrites laravel/boost's content. Install sandermuller/project-boost-laravel so its "
                . "`project-boost:sync` injects laravel/boost's skills + guidelines into the assembly, or do "
                . 'not run boost-core sync on this project.',
            );

            return;
        }

        $io->note(
            'laravel/boost + project-boost-laravel detected. Division of labor: laravel/boost owns the MCP '
            . 'server + Laravel docs; boost-core assembles the guidance files (CLAUDE.md/AGENTS.md/…) and fans '
            . 'skills out to every agent. Sync with `php artisan project-boost:sync` — it injects laravel/boost\'s '
            . 'skills + guidelines — NOT bare `vendor/bin/boost sync`, which composes a thinner set and would '
            . "overwrite laravel/boost's guidance.",
        );

        $foreignSeeded = $this->foreignSeededGuidanceFiles($projectRoot, $inConfigDir);

        if ($foreignSeeded !== []) {
            $io->warning(sprintf(
                '%d guidance file(s) carry laravel/boost-authored content boost-core does not own — a bare '
                . '`vendor/bin/boost sync` would overwrite them, and any hand-edits would be lost. Run '
                . '`php artisan project-boost:reconcile` to capture that content into `.ai/guidelines/` before '
                . "the next sync:\n  - %s",
                count($foreignSeeded),
                implode("\n  - ", $foreignSeeded),
            ));
        }
    }

    /**
     * Guidance files that EXIST, carry the laravel/boost marker, and are NOT
     * boost-owned (per the prior manifest) — the foreign-seeded set. The marker is
     * the positive foreign-writer proof, so this works even with no manifest yet
     * (a project right after `laravel/boost boost:install`, before any boost sync).
     *
     * @return list<string>
     */
    private function foreignSeededGuidanceFiles(string $projectRoot, bool $inConfigDir): array
    {
        $manifest = SyncManifest::fromProjectRoot($projectRoot, $inConfigDir);

        $seeded = [];
        foreach ($this->guidancePaths() as $relative) {
            $absolute = $projectRoot . '/' . $relative;
            if (! is_file($absolute)) {
                continue;
            }

            $content = @file_get_contents($absolute);
            if ($content === false || ! str_contains($content, self::LARAVEL_BOOST_MARKER)) {
                continue; // no foreign marker → not classifiable as foreign-seeded here
            }

            if ($manifest->ownsGuidance($relative, hash('sha256', $content))) {
                continue; // boost owns this exact content → not foreign-seeded
            }

            $seeded[] = $relative;
        }

        sort($seeded);

        return $seeded;
    }

    /**
     * The distinct guidance-file relative paths across all known agents (a
     * foreign-seeded file may belong to an agent not currently configured).
     *
     * @return list<string>
     */
    private function guidancePaths(): array
    {
        $paths = [];
        foreach (SyncEngine::allAgentTargets() as $target) {
            $relative = $target->guidelinesFileRelative();
            if ($relative !== null) {
                $paths[$relative] = true;
            }
        }

        return array_keys($paths);
    }
}
