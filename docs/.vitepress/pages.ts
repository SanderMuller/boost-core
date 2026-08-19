/**
 * Single source of truth for the documentation structure.
 *
 * The site covers the whole boost family, so pages are grouped twice:
 *
 *   area    — what the top navigation switches between: the shared guide, one
 *             entry per family package, and the reference. An area owns one
 *             sidebar, so a reader never sees another package's sections.
 *   section — the sidebar groups inside an area.
 *
 * `file` is the path under `docs/` without the extension, so it is also the
 * route. `blurb` is what the end-of-page "Next" card shows, so it reads as a
 * reason to continue rather than a bare page title.
 */
export type DocPage = {
    file: string
    text: string
    blurb: string
}

export type DocSection = {
    text: string
    pages: DocPage[]
}

export type DocArea = {
    /** Route prefix the sidebar is keyed on, without the leading or trailing slash. */
    base: string
    /** Label in the top navigation. */
    text: string
    /** Composer package the area documents. Absent on the guide and the reference. */
    composer?: string
    /** One-line summary, used by the package index and the llms.txt entry. */
    blurb?: string
    sections: DocSection[]
}

export const areas: DocArea[] = [
    {
        base: 'guide',
        text: 'Guide',
        sections: [
            {
                text: 'Getting started',
                pages: [
                    {
                        file: 'guide/why-boost',
                        text: 'Why boost?',
                        blurb: 'What it does that `laravel/boost` does not, and when you need neither.',
                    },
                    {
                        file: 'guide/what-is-boost',
                        text: 'What boost does',
                        blurb: 'One `.ai/` source, nine agents, and the family of packages built on the engine.',
                    },
                    {
                        file: 'guide/which-package',
                        text: 'Which package fits',
                        blurb: 'Pick one family member from your role: application, package, Laravel, or plain PHP.',
                    },
                    {
                        file: 'guide/installation',
                        text: 'Installation',
                        blurb: 'Require the family package, scaffold `boost.php`, and run the first sync.',
                    },
                    {
                        file: 'guide/how-sync-works',
                        text: 'How sync works',
                        blurb: 'What you author under `.ai/`, and what each agent receives.',
                    },
                ],
            },
            {
                text: 'Authoring',
                pages: [
                    {
                        file: 'guide/skill-sources',
                        text: 'Skill sources',
                        blurb: 'Host skills, vendor packages, and remote GitHub repos, resolved side by side.',
                    },
                    {
                        file: 'guide/remote-skills',
                        text: 'Remote skills',
                        blurb: 'Read a GitHub repo of skills, pick what you want, and pin what you got.',
                    },
                    {
                        file: 'guide/commands',
                        text: 'Commands',
                        blurb: 'Author a slash command once; sync transpiles the arguments per agent.',
                    },
                    {
                        file: 'guide/skill-rendering',
                        text: 'Skill rendering',
                        blurb: 'Template-flavored skills, the renderer dispatch, and how a failure surfaces.',
                    },
                ],
            },
            {
                text: 'Scoping and context',
                pages: [
                    {
                        file: 'guide/tags-and-dependencies',
                        text: 'Tags and dependencies',
                        blurb: 'Keep an unwanted skill out of a repo, and keep a hand-off pair together.',
                    },
                    {
                        file: 'guide/conventions',
                        text: 'Project Conventions',
                        blurb: 'Feed a vendor skill your Jira key, branch pattern, and test runner.',
                    },
                ],
            },
            {
                text: 'Lifecycle',
                pages: [
                    {
                        file: 'guide/file-ownership',
                        text: 'File ownership',
                        blurb: 'The manifest, what sync reaps, and why a hand-edited file is never blanked.',
                    },
                    {
                        file: 'guide/automating-sync',
                        text: 'Automating the sync',
                        blurb: 'Re-sync on `composer install`, or let a globally-installed CLI tool sync itself.',
                    },
                    {
                        file: 'guide/laravel-coexistence',
                        text: 'Coexistence with laravel/boost',
                        blurb: 'Two tools writing the same guidance files, and the rule that keeps both safe.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/project-boost-php',
        text: 'project-boost-php',
        composer: 'sandermuller/project-boost-php',
        blurb: 'AI agent skills for PHP application developers, on any framework or none.',
        sections: [
            {
                text: 'project-boost-php',
                pages: [
                    {
                        file: 'packages/project-boost-php/index',
                        text: 'Overview',
                        blurb: 'What the package adds on top of the engine, and who it is for.',
                    },
                    {
                        file: 'packages/project-boost-php/install',
                        text: 'Install and first run',
                        blurb: 'Require the package, pick your agents, and run the first sync.',
                    },
                    {
                        file: 'packages/project-boost-php/configuration',
                        text: 'Configuration',
                        blurb: 'The `boost.php` keys this package cares about, and the auto-sync hook.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/project-boost-laravel',
        text: 'project-boost-laravel',
        composer: 'sandermuller/project-boost-laravel',
        blurb: 'Sync skills and guidelines for Laravel apps, next to laravel/boost.',
        sections: [
            {
                text: 'project-boost-laravel',
                pages: [
                    {
                        file: 'packages/project-boost-laravel/index',
                        text: 'Overview',
                        blurb: 'What it adds on top of laravel/boost, and which tool owns which file.',
                    },
                    {
                        file: 'packages/project-boost-laravel/install',
                        text: 'Install and first run',
                        blurb: 'Require the package, run `project-boost:sync`, and check the result.',
                    },
                    {
                        file: 'packages/project-boost-laravel/configuration',
                        text: 'Configuration',
                        blurb: 'Artisan commands, the Blade renderer, and the defensive flags.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/package-boost-php',
        text: 'package-boost-php',
        composer: 'sandermuller/package-boost-php',
        blurb: 'AI agent skills for framework-agnostic Composer package authors.',
        sections: [
            {
                text: 'package-boost-php',
                pages: [
                    {
                        file: 'packages/package-boost-php/index',
                        text: 'Overview',
                        blurb: 'The package-author skill set, and the commands that ship with it.',
                    },
                    {
                        file: 'packages/package-boost-php/install',
                        text: 'Install and first run',
                        blurb: 'Require the package in your own package repo and sync.',
                    },
                    {
                        file: 'packages/package-boost-php/configuration',
                        text: 'Configuration',
                        blurb: 'Opt-in tags, coexistence, and the auto-sync hook.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/package-boost-laravel',
        text: 'package-boost-laravel',
        composer: 'sandermuller/package-boost-laravel',
        blurb: 'Laravel-flavored skills for package authors, on top of package-boost-php.',
        sections: [
            {
                text: 'package-boost-laravel',
                pages: [
                    {
                        file: 'packages/package-boost-laravel/index',
                        text: 'Overview',
                        blurb: 'What it inherits, what it adds, and the `.mcp.json` emitter.',
                    },
                    {
                        file: 'packages/package-boost-laravel/install',
                        text: 'Install and first run',
                        blurb: 'Require the package in a Laravel package repo and sync.',
                    },
                    {
                        file: 'packages/package-boost-laravel/configuration',
                        text: 'Configuration',
                        blurb: 'Inheritance from package-boost-php, and the keys you set here.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/boost-skills',
        text: 'boost-skills',
        composer: 'sandermuller/boost-skills',
        blurb: 'An optional catalog of ready-made skills, adopted by allowlisting its vendor.',
        sections: [
            {
                text: 'boost-skills',
                pages: [
                    {
                        file: 'packages/boost-skills/index',
                        text: 'Overview',
                        blurb: 'A published skill catalog you can adopt whole, or copy as a template.',
                    },
                    {
                        file: 'packages/boost-skills/catalog',
                        text: 'Skill catalog',
                        blurb: 'Every skill in the library, with its tags and its hand-offs.',
                    },
                ],
            },
        ],
    },
    {
        base: 'packages/boost-core',
        text: 'boost-core',
        composer: 'sandermuller/boost-core',
        blurb: 'The sync engine itself. Install it directly to ship your own skill bundle.',
        sections: [
            {
                text: 'boost-core',
                pages: [
                    {
                        file: 'packages/boost-core/index',
                        text: 'Overview',
                        blurb: 'When to install the bare engine instead of a family wrapper.',
                    },
                    {
                        file: 'packages/boost-core/install',
                        text: 'Install and first run',
                        blurb: 'Require the engine, scaffold the config, and sync.',
                    },
                    {
                        file: 'packages/boost-core/publishing-skills',
                        text: 'Publishing a skill package',
                        blurb: 'Ship `resources/boost/skills/` and let every consumer allowlist your vendor.',
                    },
                ],
            },
        ],
    },
    {
        base: 'reference',
        text: 'Reference',
        sections: [
            {
                text: 'Reference',
                pages: [
                    {
                        file: 'reference/cli',
                        text: 'CLI reference',
                        blurb: 'Every command, its options, and the exit codes CI can gate on.',
                    },
                    {
                        file: 'reference/configuration',
                        text: 'Configuration reference',
                        blurb: 'Every `BoostConfig` method, with defaults and what each one changes.',
                    },
                    {
                        file: 'reference/environment',
                        text: 'Environment variables',
                        blurb: 'Every opt-in variable, and what it turns off or escalates.',
                    },
                    {
                        file: 'reference/versioning',
                        text: 'Versioning and stability',
                        blurb: 'What the semver promise covers, and what may change in any release.',
                    },
                ],
            },
        ],
    },
]

export const packageAreas: DocArea[] = areas.filter(area => area.base.startsWith('packages/'))

/** Flat reading order — drives the "Next" call to action and llms-full.txt. */
export const pages: DocPage[] = areas.flatMap(area => area.sections.flatMap(section => section.pages))

/** A folder page is served from its directory, so `packages/x/index` is `/packages/x/`. */
export const link = (file: string) => `/${file.replace(/\/index$/, '/')}`

export const areaOf = (file: string) => areas.find(area => file.startsWith(`${area.base}/`))
