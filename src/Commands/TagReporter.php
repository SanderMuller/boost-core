<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
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

        $skills = [];
        $guidelines = [];
        foreach ($scanner->discover() as $vendor) {
            if (! $config->isVendorAllowed($vendor->name)) {
                continue;
            }

            if ($vendor->skillsPath !== null) {
                foreach ($skillLoader->load($vendor->skillsPath, $vendor->name) as $skill) {
                    $skills[] = $skill;
                }
            }

            if ($vendor->guidelinesPath !== null) {
                foreach ($guidelineLoader->load($vendor->guidelinesPath, $vendor->name) as $guideline) {
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
            $io->writeln('<comment>Declared tags matched by no installed skill or guideline (possible typo): '
                . implode(', ', $declaredButUnused) . '</comment>');
        }

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
