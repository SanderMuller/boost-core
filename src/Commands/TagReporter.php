<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillTagDiagnostics;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the tag report shared by `boost:tags` and `boost:doctor`: the
 * project's declared tags, per-skill and per-guideline tag status, the tag
 * vocabulary in use across installed skills + guidelines, hygiene hints, and
 * the "declare tag X to enable skills Y" roll-up.
 *
 * Header-agnostic — the caller frames it (`boost:doctor` as one of its
 * sections, `boost:tags` under its own title).
 */
final readonly class TagReporter
{
    public function __construct(
        private SkillTagDiagnostics $diagnostics = new SkillTagDiagnostics(),
        private ?InstalledPackages $injectedPackages = null,
    ) {}

    public function report(SymfonyStyle $io, BoostConfig $config): void
    {
        if ($config->tags === []) {
            $io->writeln('<comment>No tags declared. Every untagged skill and guideline ships; a tagged vendor item is filtered out until you `withTags()` its tags.</comment>');
        } else {
            $io->writeln('Declared tags: <info>' . implode(', ', $config->tags) . '</info>');
        }

        ['skills' => $skills, 'guidelines' => $guidelines] = $this->collect($config);

        if ($skills === [] && $guidelines === []) {
            $io->writeln('<info>No allowlisted vendor skills or guidelines installed.</info>');

            // Entry-point hint at the no-skills-loaded gate. Bare-CLI bypasses
            // the wrapper's skill-injection pipeline, producing zero skills.
            // The diagnostic surfaces the most likely root cause + the artisan
            // fix path before the operator assumes "no allowlisted vendors ==
            // legitimately empty."
            $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
            if ($packages->has('sandermuller/project-boost-laravel')) {
                $io->writeln('<comment>project-boost-laravel detected. '
                    . 'If you expected bundled skills (pest-testing, livewire-development, '
                    . "filament-development, etc.), bare-CLI bypasses the wrapper's "
                    . 'skill-injection pipeline — producing cross-agent capability asymmetry. '
                    . 'Claude Code may mask the absence via laravel/boost MCP server; '
                    . 'Cursor / Copilot / Codex silently miss bundled skills. '
                    . 'Run via `php artisan project-boost:sync` to deliver them to all '
                    . 'active agents equally.</comment>');
            }

            return;
        }

        if ($skills !== []) {
            $this->renderSkillTable($io, $config, $skills);
        }

        if ($guidelines !== []) {
            $this->renderGuidelineTable($io, $config, $guidelines);
        }

        $this->renderHygiene($io, $config, $skills, $guidelines);
        $this->renderEnableable($io, $config, $skills);
    }

    /**
     * Skills and guidelines published by allowlisted, installed vendor
     * packages — gathered in a single vendor-discovery pass. Each
     * `DiscoveredVendor` already exposes both `skillsPath` and
     * `guidelinesPath`, so one `discover()` walk covers both.
     *
     * @return array{skills: list<Skill>, guidelines: list<Guideline>}
     */
    private function collect(BoostConfig $config): array
    {
        $skillLoader = new SkillLoader(new FrontmatterParser());
        $guidelineLoader = new GuidelineLoader(new FrontmatterParser());
        $scanner = new VendorScanner(InstalledPackages::fromComposer());

        // Pass the same renderer dispatcher SyncEngine would use, so
        // `boost tags` / `boost doctor` discover renderer-claimed
        // extensions (e.g. `.blade.php` with a registered BladeRenderer)
        // — otherwise reporting commands silently miss the same files
        // sync would emit, confusing users who rely on tag-reporter for
        // discovery.
        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        $skills = [];
        $guidelines = [];
        foreach ($scanner->discover() as $vendor) {
            if (! $config->isVendorAllowed($vendor->name)) {
                continue;
            }

            if ($vendor->skillsPath !== null) {
                foreach ($skillLoader->load($vendor->skillsPath, $vendor->name, $dispatcher) as $skill) {
                    $skills[] = $skill;
                }
            }

            if ($vendor->guidelinesPath !== null) {
                foreach ($guidelineLoader->load($vendor->guidelinesPath, $vendor->name, $dispatcher) as $guideline) {
                    $guidelines[] = $guideline;
                }
            }
        }

        return ['skills' => $skills, 'guidelines' => $guidelines];
    }

    /**
     * @param  list<Skill>  $skills
     */
    private function renderSkillTable(SymfonyStyle $io, BoostConfig $config, array $skills): void
    {
        /** @var list<array{0: string, 1: string, 2: string}> $rows */
        $rows = [];
        foreach ($skills as $skill) {
            $rows[] = [
                $skill->sourceVendor ?? '(host)',
                $skill->name,
                $this->diagnostics->status($skill, $config),
            ];
        }

        $io->table(['Vendor', 'Skill', 'Tag status'], $rows);
    }

    /**
     * @param  list<Guideline>  $guidelines
     */
    private function renderGuidelineTable(SymfonyStyle $io, BoostConfig $config, array $guidelines): void
    {
        /** @var list<array{0: string, 1: string, 2: string}> $rows */
        $rows = [];
        foreach ($guidelines as $guideline) {
            $rows[] = [
                $guideline->sourceVendor ?? '(host)',
                $guideline->name,
                $this->diagnostics->guidelineStatus($guideline, $config),
            ];
        }

        $io->table(['Vendor', 'Guideline', 'Tag status'], $rows);
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     */
    private function renderHygiene(SymfonyStyle $io, BoostConfig $config, array $skills, array $guidelines): void
    {
        $tagUnion = $this->tagUnion($skills, $guidelines);

        if ($tagUnion !== []) {
            $io->writeln('Tags in use by installed skills and guidelines: <info>' . implode(', ', $tagUnion) . '</info>');
        }

        $declaredButUnused = $this->diagnostics->declaredButUnusedTags($config, $tagUnion);
        if ($declaredButUnused !== []) {
            // Three-case split for declared-but-unused tags. Same
            // raw observation, different probable root causes + different
            // operator fix paths. Detection ordering: typo (cheapest) →
            // bare-CLI-without-wrapper-injection (Laravel context) →
            // forward-compat declaration (everything else).
            $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
            $wrapperInstalled = $packages->has('sandermuller/project-boost-laravel');

            if ($wrapperInstalled) {
                // Case 3: bare-CLI-without-wrapper-injection. Tags resolve to no
                // shipped skills because bare-CLI invocation bypasses the wrapper's
                // skill-injection pipeline. The bare-CLI gap may be invisible in
                // MCP-enabled agents (e.g., Claude Code via laravel/boost MCP
                // server) while affecting Cursor / Copilot / Codex. Surface this
                // case with explicit fix path before the generic forward-compat
                // wording fires.
                $io->writeln('<comment>Declared tags matched by no installed skill or guideline: '
                    . implode(', ', $declaredButUnused) . '. project-boost-laravel detected — '
                    . "bare-CLI bypasses the wrapper's skill-injection pipeline. "
                    . 'Run via `php artisan project-boost:sync` to deliver bundled skills '
                    . 'to all active agents equally (Cursor / Copilot / Codex silently miss '
                    . 'them under bare CLI; Claude Code may mask via laravel/boost MCP server).</comment>');
            } else {
                // Case 2: forward-compat declaration. The operator declared a
                // tag now (or one that's spelled correctly + valid) but no
                // installed skill targets it yet. Harmless per the picker's
                // preservation rule — declared tags survive across re-installs
                // even when no installed vendor publishes a matching skill.
                $io->writeln('<comment>Declared tags matched by no installed skill or guideline: '
                    . implode(', ', $declaredButUnused) . '. '
                    . 'If the tag spelling looks right, this is a forward-compat declaration — '
                    . 'declared tags survive across `boost install` re-runs even when no installed '
                    . 'vendor currently publishes a matching skill.</comment>');
            }
        }

        // Case 1: actual typo. Near-duplicate detection runs over the union
        // of installed-tags + declared-tags — surfaces tags that look alike
        // (probable misspelling of an existing tag). Fires alongside the
        // forward-compat / bare-CLI wording above when both apply (a typo'd
        // declaration is also declared-but-unused).
        foreach ($this->diagnostics->nearDuplicates([...$tagUnion, ...$config->tags]) as $pair) {
            $io->writeln('<comment>Possible tag typo — these look alike: '
                . $pair[0] . ' / ' . $pair[1] . '</comment>');
        }
    }

    /**
     * The "add tag → unlock skills" roll-up. Skills only — a filtered
     * guideline's missing tags already show in the guideline table's
     * `filtered (declare: ...)` status, and guidelines have no exclude
     * layer that would complicate the grouping.
     *
     * @param  list<Skill>  $skills
     */
    private function renderEnableable(SymfonyStyle $io, BoostConfig $config, array $skills): void
    {
        $groups = $this->diagnostics->filteredSkillsByMissingTags($skills, $config);

        if ($groups === []) {
            return;
        }

        $io->newLine();
        $io->writeln('<comment>Filtered skills you could enable — add the tag to `withTags()`:</comment>');
        foreach ($groups as $group) {
            $io->writeln(sprintf(
                '  declare <info>%s</info> → %s',
                implode(' + ', $group['tags']),
                implode(', ', $group['skills']),
            ));
        }
    }

    /**
     * Distinct tags declared across every collected skill and guideline.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @return list<string>
     */
    private function tagUnion(array $skills, array $guidelines): array
    {
        /** @var array<string, true> $union */
        $union = [];
        foreach ($skills as $skill) {
            foreach ($skill->tags as $tag) {
                $union[$tag] = true;
            }
        }

        foreach ($guidelines as $guideline) {
            foreach ($guideline->tags as $tag) {
                $union[$tag] = true;
            }
        }

        return array_keys($union);
    }
}
