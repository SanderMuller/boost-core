<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;

/**
 * Classifies conventions-token leaks in emitted content (0.16.0 conventions-token
 * observability — spec internal/specs/conventions-token-observability.md).
 *
 * Wraps {@see ConventionsInliner::scanLeaks()} (which finds raw tokens / surviving
 * opt-in fences) and attaches a human-actionable cause to each, using the live
 * {@see SlotResolver} to distinguish:
 *
 *  - a prose token that RESOLVES fine → the value should have been inlined; the
 *    emitting engine was likely pre-0.15 (failure mode A) or the file is a stale
 *    emit (mode C). Remedy: re-sync with boost-core ≥0.15.
 *  - a prose token that ERRORS → a genuine resolution fault (mode B): the
 *    resolver's own message (unknown slot, type×mode mismatch, …) is the cause.
 *  - a malformed token (missing path/mode) → reported as such.
 *  - a surviving ` ```boost:conv ` fence opener → the fence was never processed
 *    (pre-0.15 emit / drift); the info-string would have been stripped otherwise.
 *
 * Shared by the three observability legs (sync-time self-check, `boost doctor`,
 * `boost validate`) so all three classify a leak identically.
 */
final readonly class ConventionTokenLeakScanner
{
    private const RESYNC_REMEDY = 're-sync with boost-core ≥0.15';

    public function __construct(
        private ConventionsInliner $inliner,
        private SlotResolver $resolver,
    ) {}

    /**
     * Build a scanner with the SAME resolver + inliner sync uses, from the
     * installed packages + config. Mirrors {@see SyncEngine::conventionsContext()}'s
     * resolver/inliner construction (schema discovery → compose → slot roots);
     * keep the two in lockstep if that build changes.
     */
    public static function fromConfig(InstalledPackages $packages, BoostConfig $config): self
    {
        ['sources' => $sources] = (new SchemaDiscovery($packages))->discover(
            $config->allowedVendors,
            conventionsDeclared: $config->conventions !== [],
        );

        $composed = $sources === [] ? [] : (new ConventionsSchema($sources))->compose();

        /** @var list<string> $slotRoots */
        $slotRoots = [];
        $properties = $composed['properties'] ?? null;
        if (is_array($properties)) {
            foreach (array_keys($properties) as $root) {
                if (is_string($root) && $root !== 'schema-version') {
                    $slotRoots[] = $root;
                }
            }
        }

        $resolver = new SlotResolver($config->conventions, $composed);

        return new self(new ConventionsInliner($resolver, $slotRoots), $resolver);
    }

    /**
     * Scan every EMITTED file for the active agent set (guidance + per-agent
     * SKILL.md, incl. gitignored copies) and return all classified leaks. The
     * shared on-disk audit behind `boost doctor` and `boost validate`.
     *
     * @return list<TokenLeak>
     */
    public function scanEmitted(string $projectRoot, BoostConfig $config, ?EmittedAgentFiles $files = null): array
    {
        $files ??= EmittedAgentFiles::default();

        $leaks = [];
        foreach ($files->forConfig($projectRoot, $config) as $file) {
            $content = @file_get_contents($file['absolute']);
            if ($content === false) {
                continue;
            }

            foreach ($this->scan($file['relative'], $content) as $leak) {
                $leaks[] = $leak;
            }
        }

        return $leaks;
    }

    /**
     * @return list<TokenLeak>
     */
    public function scan(string $relativePath, string $content): array
    {
        $leaks = [];
        foreach ($this->inliner->scanLeaks($content) as $hit) {
            $leaks[] = new TokenLeak(
                relativePath: $relativePath,
                line: $hit->line,
                kind: $hit->kind,
                path: $hit->path,
                mode: $hit->mode,
                cause: $this->cause($hit),
            );
        }

        return $leaks;
    }

    private function cause(LeakHit $hit): string
    {
        if ($hit->kind === LeakHit::KIND_FENCE_OPENER) {
            // The info-string survives in two cases: a pre-0.15 engine never
            // processed the fence (mode A), OR a 0.15+ engine processed it but a
            // token inside failed to resolve, so it KEPT the info-string to keep
            // the leak visible (fenced mode B). Both: re-sync on ≥0.15 + fix any
            // reported slot/mode error.
            return 'surviving `boost:conv` fence — not cleanly processed (emitting engine <0.15, or a token inside failed to resolve); ' . self::RESYNC_REMEDY . ' and fix any reported slot/mode error';
        }

        if ($hit->path === null) {
            return 'malformed boost:conv token (missing required "path")';
        }

        if ($hit->mode === null) {
            return sprintf('malformed boost:conv token for "%s" (missing required "mode")', $hit->path);
        }

        $resolution = $this->resolver->resolve($hit->path, $hit->mode, null);
        if ($resolution->ok) {
            // Resolves cleanly on this engine, yet sits raw on disk → it was
            // emitted by an engine that could not resolve it (pre-0.15) or the
            // file is stale (modes A / C).
            return sprintf('slot "%s" left unresolved (emitting engine likely <0.15, or stale emit); %s', $hit->path, self::RESYNC_REMEDY);
        }

        // Genuine resolution fault on this engine (mode B): surface the
        // resolver's own actionable message.
        return (string) $resolution->error;
    }
}
