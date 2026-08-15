<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncManifest;
use Symfony\Component\Console\Style\SymfonyStyle;
use UnexpectedValueException;

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

    /**
     * The wrapper release that introduced `project-boost:reconcile` — the guided
     * takeover purpose-built for foreign-seeded files. Below this, the command does
     * not exist, so the foreign-seeded warning must steer to `project-boost:sync`.
     */
    private const RECONCILE_MIN_WRAPPER_VERSION = '1.1.0';

    /**
     * The wrapper release whose `project-boost:sync` removes laravel/boost's
     * `boost.json` after a successful sync (with `--keep-boost-json` to opt out).
     * Below it, the operator has to delete the file themselves — so the advice
     * has to differ.
     */
    private const BOOST_JSON_REMOVAL_MIN_WRAPPER_VERSION = '1.2.0';

    public function report(SymfonyStyle $io, string $projectRoot, InstalledPackages $packages, bool $inConfigDir, ?string $skillsPath = null): void
    {
        if (! $packages->has('laravel/boost')) {
            return; // not a coexistence project — silent
        }

        $io->section('laravel/boost coexistence');

        // These three hold whether or not the wrapper is installed: the two
        // command families collide by name everywhere, and laravel/boost writes
        // into boost-managed directories in both layouts.
        $this->reportCommandSurfaces($io, $packages);
        $this->reportForeignSkillDirectories($io, $projectRoot, $inConfigDir);
        $this->reportAuthoredSkillCollisions($io, $projectRoot);
        $this->reportRulesDirectory($io, $projectRoot);
        $this->reportSourceAdoption($io, $projectRoot, $packages, $skillsPath);
        $this->reportSymlinkedSkillTargets($io, $projectRoot);

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
            // Prefer the purpose-built `project-boost:reconcile` guided takeover — but ONLY
            // when the installed wrapper is >= 1.1.0, the release that introduced it. On an
            // older wrapper that command does not exist, so steer to `project-boost:sync`,
            // which re-derives laravel/boost's bundled guidelines into the assembly safely.
            // (Conservative: a dev/unparseable wrapper version falls back to :sync too —
            // naming a possibly-absent command is the wrong-path bug we are avoiding.) The
            // genuine at-risk content is a direct HAND-EDIT of the seeded file (it does not
            // re-derive) — call that out as the thing to capture first, either way.
            $command = $this->wrapperHasReconcile($packages)
                ? 'project-boost:reconcile'
                : 'project-boost:sync';

            $io->warning(sprintf(
                '%d guidance file(s) carry laravel/boost-authored content boost-core does not own yet. Run '
                . "`php artisan %s` to take them over — laravel/boost's bundled guidelines re-derive into the "
                . 'assembly safely. If you HAND-EDITED any of these files directly, move those edits into '
                . "`.ai/guidelines/` first, or the takeover will replace them:\n  - %s",
                count($foreignSeeded),
                $command,
                implode("\n  - ", $foreignSeeded),
            ));
        }
    }

    /**
     * The command-surface map. Both packages own a `boost:` command namespace,
     * so the same words mean different things depending on the entry point —
     * the concrete failure being an agent that reads "run boost sync" and runs
     * `php artisan boost:update` (or the reverse). Spell out which binary owns
     * which verb, and which entry point is the one to use here.
     */
    private function reportCommandSurfaces(SymfonyStyle $io, InstalledPackages $packages): void
    {
        $hasWrapper = $packages->has(self::WRAPPER_PACKAGE);

        $lines = [
            'Two command families share the `boost` name — they are NOT interchangeable:',
            '  • `php artisan boost:*`  → laravel/boost (`boost:install`, `boost:update`, `boost:mcp`, `boost:add-skill`). Owns the MCP server + its own bundled Laravel guidelines/skills.',
            '  • `vendor/bin/boost *`   → boost-core (`install`, `sync`, `doctor`, `where`, `validate`, …). Owns the guidance-file assembly + the skill fan-out to every agent.',
        ];

        if ($hasWrapper) {
            $lines[] = '  • `php artisan project-boost:*` → project-boost-laravel. `project-boost:sync` is THE sync to run here: it injects laravel/boost\'s skills + guidelines into the boost-core assembly. Bare `vendor/bin/boost sync` composes a thinner set.';
        }

        $lines[] = '';
        $lines[] = "Beware of the automatic trigger: `herd link` runs `php artisan boost:update` by itself whenever `vendor/laravel/boost` is present (Herd's valet CLI does this after linking a site). That re-seeds laravel/boost's guidelines + skills, so the next boost-core sync reports guidance takeover. Re-run "
            . ($hasWrapper ? '`php artisan project-boost:sync`' : '`vendor/bin/boost sync`')
            . ' afterwards to converge.';

        $io->text($lines);
        $io->newLine();
    }

    /**
     * `.ai/skills/` is ALSO laravel/boost's explicit user-skill glob
     * (`SkillComposer::discoverExplicitUserSkills()` globs `base_path('.ai/skills')/*`
     * and treats every directory there as a custom skill of its own). Its install
     * then publishes those directly to ITS configured agents — as a symlink to the
     * source, or a copy — bypassing boost-core's rendering, tag filtering and
     * per-agent formatting. That same directory's existence also keeps
     * `boost:update` alive: its gate is `config->hasSkills() || is_dir('.ai/skills')`,
     * so it re-runs the install even with an empty `skills` list in `boost.json`.
     *
     * Nothing else reads `boost.json`: not the MCP server (`boost:mcp` →
     * `mcp:start laravel-boost`, registered unconditionally by its service
     * provider), not the wrapper (it re-derives from `vendor/laravel/boost/.ai/`),
     * not boost-core. Only `boost:install` and `boost:update` do — and
     * `boost:update` refuses to run at all without a valid one. So on a wrapper
     * project the file is optional, and removing it stops the automatic re-seed
     * outright. Stated as an option; deleting another tool's state file is the
     * operator's call.
     */
    private function reportSourceAdoption(SymfonyStyle $io, string $projectRoot, InstalledPackages $packages, ?string $skillsPath): void
    {
        if ($skillsPath === null) {
            return;
        }

        if (rtrim($skillsPath, '/') !== $projectRoot . '/.ai/skills') {
            return; // host source already sits outside laravel/boost's glob
        }

        if (! is_dir($skillsPath)) {
            return;
        }

        $hasWrapper = $packages->has(self::WRAPPER_PACKAGE);

        $io->note(
            "Host skills live in `.ai/skills/`, which is also laravel/boost's user-skill directory: its "
            . '`boost:install` / `boost:update` picks up every directory there and publishes it to its own agents '
            . "(symlink or copy), bypassing boost-core's rendering and tag filtering. That directory existing also "
            . 'keeps `boost:update` doing work regardless of the `skills` list in `boost.json`.'
            . ($hasWrapper
                ? ($this->wrapperRemovesBoostJson($packages)
                    ? "\nThe re-seed stops once `boost.json` is gone (`boost:update` refuses to run without it), and a "
                        . 'successful `php artisan project-boost:sync` retires that file for you — archiving it under '
                        . "boost's state dir once its agent list is declared here, so nothing is lost. Pass "
                        . '`--keep-boost-json` to opt out, `php artisan boost:install` to bring it back.'
                    : "\nTo stop the automatic re-seed entirely, DELETE `boost.json`: `boost:update` refuses to run "
                        . 'without it, so the `herd link` trigger becomes a no-op. Nothing else needs the file — the MCP '
                        . "server does not read it, and the wrapper re-derives laravel/boost's guidelines and skills from "
                        . '`vendor/laravel/boost/.ai/` on every `project-boost:sync`. Running `php artisan boost:install` '
                        . 'again recreates it.')
                    . "\nNarrower alternative, if the file is wanted: `\"guidelines\": false` + `\"skills\": []` in "
                    . '`boost.json` AND move the host source out of `.ai/skills/` with `->withSkillsPath(...)` — '
                    . 'both are needed, since the directory alone keeps `boost:update` alive.'
                : "\nDeleting `boost.json` would stop the automatic re-seed (`boost:update` refuses to run without "
                    . "it), but WITHOUT the project-boost-laravel wrapper there is no injection path, so laravel/boost's "
                    . 'bundled guidelines and skills would then never reach this project at all.'),
        );
    }

    /**
     * Skill targets that are symlinks. laravel/boost's custom-skill writer links
     * `<agent skills dir>/<name>` at `.ai/skills/<name>`; boost-core never writes
     * through a live symlink (`WriteAction::SKIPPED_SYMLINK`), so its rendered,
     * tag-filtered output is silently displaced by the raw source and `sync --check`
     * reports no drift. `boost sync` notes the skip; doctor names the consequence.
     */
    private function reportSymlinkedSkillTargets(SymfonyStyle $io, string $projectRoot): void
    {
        $linked = [];
        foreach ($this->skillDirectories() as $relativeDir) {
            $entries = @scandir($projectRoot . '/' . $relativeDir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }

                if ($entry === '..') {
                    continue;
                }

                if (is_link($projectRoot . '/' . $relativeDir . '/' . $entry)) {
                    $linked[] = $relativeDir . '/' . $entry;
                }
            }
        }

        if ($linked === []) {
            return;
        }

        sort($linked);

        $io->warning(sprintf(
            '%d skill target(s) are symlinks (laravel/boost links its custom skills straight at the source tree). '
            . 'boost-core never writes through a live symlink, so the agent reads the RAW source instead of the '
            . 'rendered, tag-filtered output — and `sync --check` shows no drift. To hand these back to boost-core, '
            . "remove the links and re-sync:\n  - %s",
            count($linked),
            implode("\n  - ", $linked),
        ));
    }

    /**
     * `.ai/rules/` is laravel/boost's own channel: its bundled guideline tells
     * agents they MUST read `.ai/rules/index.md` before touching a file, and its
     * MCP `record-rule` tool writes there. boost-core composes `.ai/guidelines/`
     * and `.ai/skills/` — it has no `.ai/rules` pipeline at all — so those rules
     * never enter the assembly and never reach the agents that only read the
     * generated guidance files. Point that split out rather than let it look like
     * one shared source tree.
     */
    private function reportRulesDirectory(SymfonyStyle $io, string $projectRoot): void
    {
        if (! is_dir($projectRoot . '/.ai/rules')) {
            return;
        }

        $io->note(
            '`.ai/rules/` exists. That directory belongs to laravel/boost (its guideline instructs agents to read '
            . '`.ai/rules/index.md` before editing, and its `record-rule` MCP tool writes there). boost-core never '
            . 'reads or composes those files — they stay path-scoped and read on demand. On a wrapper project the '
            . "instruction to read them travels with laravel/boost's own guideline through `project-boost:sync`; "
            . 'without the wrapper nothing carries it, so agents are never told the directory exists. Guidance every '
            . 'agent must always follow belongs in `.ai/guidelines/`.',
        );
    }

    /**
     * Skill directories another tool installed into the agent skill directories
     * boost-core manages. boost-core no longer deletes these (it deletes only
     * what its manifest owns), so they persist — but they are invisible to
     * `boost where`, are not fanned out to the other agents, and duplicate
     * anything the wrapper injects under the same name.
     */
    private function reportForeignSkillDirectories(SymfonyStyle $io, string $projectRoot, bool $inConfigDir): void
    {
        $manifest = SyncManifest::fromProjectRoot($projectRoot, $inConfigDir);

        // No manifest yet — the just-`boost:install`ed project, the primary
        // coexistence entry point. Nothing can be classified, but staying silent
        // would hide directories that ARE there, so say what is present and why
        // the verdict has to wait for the first sync.
        if ($manifest->isEmpty()) {
            $populated = $this->populatedSkillDirectories($projectRoot);
            if ($populated !== []) {
                $io->note(sprintf(
                    'Skill directories exist (%s) but boost-core has no ownership manifest yet, so it cannot say '
                    . 'which of them it wrote and which came from laravel/boost. Run a sync, then `boost doctor` '
                    . 'again for the split.',
                    implode(', ', $populated),
                ));
            }

            return;
        }

        $foreign = [];
        foreach ($this->skillDirectories() as $relativeDir) {
            $absoluteDir = $projectRoot . '/' . $relativeDir;
            if (! is_dir($absoluteDir)) {
                continue;
            }

            $entries = @scandir($absoluteDir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }

                if ($entry === '..') {
                    continue;
                }

                $relative = $relativeDir . '/' . $entry;
                if (! is_dir($projectRoot . '/' . $relative)) {
                    continue;
                }

                if (is_link($projectRoot . '/' . $relative)) {
                    continue;
                }

                if ($manifest->hasUnder($relative)) {
                    continue; // boost emitted this one
                }

                $foreign[] = $relative;
            }
        }

        if ($foreign === []) {
            return;
        }

        sort($foreign);

        $io->warning(sprintf(
            "%d skill director(ies) in boost-managed locations were written by another tool (laravel/boost's "
            . '`boost:install` / `boost:update` installs its bundled skills as real directories). boost-core '
            . 'preserves them — it deletes only what it owns — but they reach ONLY that agent, are absent from '
            . '`boost where`, and shadow nothing boost-core knows about. To fan them out to every agent instead, '
            . "let the wrapper inject them (`php artisan project-boost:sync`) and drop them from laravel/boost's "
            . "`boost.json` skills list:\n  - %s",
            count($foreign),
            implode("\n  - ", $foreign),
        ));
    }

    /**
     * Host-authored skills whose name laravel/boost also claims. Its
     * `SkillWriter` treats `.ai/skills/<name>` as ITS canonical copy and
     * overwrites the directory with vendor content — so a boost-core-authored
     * skill of the same name is destroyed by the next `boost:install` /
     * `boost:update` (which `herd link` triggers on its own).
     */
    private function reportAuthoredSkillCollisions(SymfonyStyle $io, string $projectRoot): void
    {
        $claimed = (new LaravelBoostState())->skillNames($projectRoot);
        if ($claimed === []) {
            return;
        }

        $collisions = [];
        foreach ($claimed as $name) {
            if (is_dir($projectRoot . '/.ai/skills/' . $name) || is_file($projectRoot . '/.ai/skills/' . $name . '.md')) {
                $collisions[] = $name;
            }
        }

        if ($collisions === []) {
            return;
        }

        sort($collisions);

        $io->warning(sprintf(
            '%d host-authored skill name(s) under `.ai/skills/` are also claimed by laravel/boost (they are listed '
            . 'in its `boost.json`). laravel/boost treats `.ai/skills/<name>` as its own canonical copy and '
            . 'OVERWRITES it with vendor content on the next `php artisan boost:install` / `boost:update` — which '
            . "`herd link` runs automatically. Rename the host skill, or drop the name from `boost.json`:\n  - %s",
            count($collisions),
            implode("\n  - ", $collisions),
        ));
    }

    /**
     * Agent skill directories that exist and hold at least one entry.
     *
     * @return list<string>
     */
    private function populatedSkillDirectories(string $projectRoot): array
    {
        $populated = [];
        foreach ($this->skillDirectories() as $relativeDir) {
            $entries = @scandir($projectRoot . '/' . $relativeDir);
            if ($entries === false) {
                continue;
            }

            if (array_values(array_diff($entries, ['.', '..'])) === []) {
                continue;
            }

            $populated[] = $relativeDir;
        }

        return $populated;
    }

    /**
     * The distinct agent skill directories across all known agents.
     *
     * @return list<string>
     */
    private function skillDirectories(): array
    {
        $dirs = [];
        foreach (SyncEngine::allAgentTargets() as $target) {
            $dirs[$target->skillsDirectoryRelative()] = true;
        }

        return array_keys($dirs);
    }

    /**
     * Whether the installed wrapper is recent enough to expose the
     * `project-boost:reconcile` guided takeover (>= {@see RECONCILE_MIN_WRAPPER_VERSION}).
     *
     * Both versions are normalized before comparison so a `v`-prefixed or
     * multi-part Composer version (`v1.1.0`, `1.1.0.0`) compares correctly. Any
     * unparseable / dev-branch version (`dev-main`) yields a conservative `false`
     * — we only name the command when the install demonstrably carries it.
     */
    private function wrapperHasReconcile(InstalledPackages $packages): bool
    {
        return $this->wrapperAtLeast($packages, self::RECONCILE_MIN_WRAPPER_VERSION);
    }

    /**
     * Whether the installed wrapper removes `boost.json` on a successful sync
     * (>= {@see BOOST_JSON_REMOVAL_MIN_WRAPPER_VERSION}). Below it, the operator
     * has to do it by hand.
     */
    private function wrapperRemovesBoostJson(InstalledPackages $packages): bool
    {
        return $this->wrapperAtLeast($packages, self::BOOST_JSON_REMOVAL_MIN_WRAPPER_VERSION);
    }

    private function wrapperAtLeast(InstalledPackages $packages, string $minimum): bool
    {
        $version = $packages->version(self::WRAPPER_PACKAGE);
        if ($version === null || $version === '') {
            return false;
        }

        $parser = new VersionParser();

        try {
            return Comparator::greaterThanOrEqualTo(
                $parser->normalize($version),
                $parser->normalize($minimum),
            );
        } catch (UnexpectedValueException) {
            return false; // dev-main / branch ref / unparseable → conservative
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
