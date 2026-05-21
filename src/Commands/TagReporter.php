<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillTagDiagnostics;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the skill-tag report shared by `boost:tags` and `boost:doctor`:
 * the project's declared tags, per-skill tag status, the tag vocabulary in
 * use across installed skills, hygiene hints, and the "declare tag X to
 * enable skills Y" roll-up.
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
            $io->writeln('<comment>No tags declared. Every untagged skill ships; a tagged vendor skill is filtered out until you `withTags()` its tag.</comment>');
        } else {
            $io->writeln('Declared tags: <info>' . implode(', ', $config->tags) . '</info>');
        }

        $skills = $this->collectSkills($config);

        if ($skills === []) {
            $io->writeln('<info>No allowlisted vendor skills installed.</info>');

            return;
        }

        $this->renderStatusTable($io, $config, $skills);
        $this->renderHygiene($io, $config, $skills);
        $this->renderEnableable($io, $config, $skills);
    }

    /**
     * Every skill published by an allowlisted, installed vendor package.
     *
     * @return list<Skill>
     */
    private function collectSkills(BoostConfig $config): array
    {
        $loader = new SkillLoader(new FrontmatterParser());
        $scanner = new VendorScanner(InstalledPackages::fromComposer());

        $skills = [];
        foreach ($scanner->discover() as $vendor) {
            if (! $config->isVendorAllowed($vendor->name)) {
                continue;
            }

            if ($vendor->skillsPath === null) {
                continue;
            }

            foreach ($loader->load($vendor->skillsPath, $vendor->name) as $skill) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * @param  list<Skill>  $skills
     */
    private function renderStatusTable(SymfonyStyle $io, BoostConfig $config, array $skills): void
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
     * @param  list<Skill>  $skills
     */
    private function renderHygiene(SymfonyStyle $io, BoostConfig $config, array $skills): void
    {
        $tagUnion = $this->tagUnion($skills);

        if ($tagUnion !== []) {
            $io->writeln('Tags in use by installed skills: <info>' . implode(', ', $tagUnion) . '</info>');
        }

        $declaredButUnused = $this->diagnostics->declaredButUnusedTags($config, $tagUnion);
        if ($declaredButUnused !== []) {
            $io->writeln('<comment>Declared tags matched by no installed skill (possible typo): '
                . implode(', ', $declaredButUnused) . '</comment>');
        }

        foreach ($this->diagnostics->nearDuplicates([...$tagUnion, ...$config->tags]) as $pair) {
            $io->writeln('<comment>Possible tag typo — these look alike: '
                . $pair[0] . ' / ' . $pair[1] . '</comment>');
        }
    }

    /**
     * The "add tag → unlock skills" roll-up: turns the per-skill `filtered`
     * rows into a direct list of which tags to declare for which skills.
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
     * Distinct tags declared across every collected skill.
     *
     * @param  list<Skill>  $skills
     * @return list<string>
     */
    private function tagUnion(array $skills): array
    {
        /** @var array<string, true> $union */
        $union = [];
        foreach ($skills as $skill) {
            foreach ($skill->tags as $tag) {
                $union[$tag] = true;
            }
        }

        return array_keys($union);
    }
}
