import { writeFileSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { defineConfig } from 'vitepress'
import { areas, link, packageAreas, pages } from './pages'

const SITE_URL = 'https://sandermuller.github.io/boost-core'
const DESCRIPTION = 'AI agent configuration sync for PHP projects. Author skills, guidelines, and commands once in .ai/, and publish them to nine agents.'

/**
 * One sidebar per area, keyed on the area's route prefix. A reader inside a
 * package section sees that package's pages only, plus two fixed groups: the
 * shared concepts that live in the guide, and the sibling packages.
 */
const sidebar = Object.fromEntries(areas.map((area) => {
    const groups = area.sections.map(section => ({
        text: section.text,
        items: section.pages.map(page => ({ text: page.text, link: link(page.file) })),
    }))

    if (area.base.startsWith('packages/')) {
        groups.push({
            text: 'Shared concepts',
            items: [
                { text: 'How sync works', link: link('guide/how-sync-works') },
                { text: 'Skill sources', link: link('guide/skill-sources') },
                { text: 'Tags and dependencies', link: link('guide/tags-and-dependencies') },
                { text: 'Project Conventions', link: link('guide/conventions') },
                { text: 'File ownership', link: link('guide/file-ownership') },
                { text: 'CLI reference', link: link('reference/cli') },
            ],
        })

        groups.push({
            text: 'Other packages',
            items: packageAreas
                .filter(other => other.base !== area.base)
                .map(other => ({ text: other.text, link: link(`${other.base}/index`) })),
        })
    }

    return [`/${area.base}/`, groups]
}))

export default defineConfig({
    title: 'boost',
    description: DESCRIPTION,
    base: '/boost-core/',
    cleanUrls: true,
    lastUpdated: true,

    sitemap: {
        // Trailing slash required: routes resolve against this URL, and
        // without it the base path segment is dropped from every entry.
        hostname: `${SITE_URL}/`,
    },

    // llms.txt (https://llmstxt.org): a machine-readable index plus the full
    // markdown corpus, generated from the same page list as the sidebar so it
    // cannot drift from the site.
    async buildEnd(siteConfig) {
        const index = [
            '# boost for PHP',
            '',
            `> ${DESCRIPTION}`,
            '',
        ]
        for (const area of areas) {
            index.push(`## ${area.text}`, '')
            if (area.composer) {
                index.push(`Composer package: \`${area.composer}\`. ${area.blurb}`, '')
            }
            for (const page of area.sections.flatMap(section => section.pages)) {
                index.push(`- [${page.text}](${SITE_URL}${link(page.file)}): ${page.blurb}`)
            }
            index.push('')
        }
        writeFileSync(resolve(siteConfig.outDir, 'llms.txt'), index.join('\n'))

        const sources = await Promise.all(
            pages.map(page => readFile(resolve(siteConfig.srcDir, `${page.file}.md`), 'utf8')),
        )
        writeFileSync(resolve(siteConfig.outDir, 'llms-full.txt'), sources.join('\n\n---\n\n'))
    },

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/boost-core/logo.svg' }],
        ['meta', { name: 'theme-color', content: '#7c5cff' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'boost for PHP' }],
        ['meta', { property: 'og:description', content: DESCRIPTION }],
        ['meta', { property: 'og:image', content: `${SITE_URL}/overview.jpg` }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
        ['meta', { name: 'twitter:image', content: `${SITE_URL}/overview.jpg` }],
    ],

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

    rewrites: {
        'home.md': 'index.md',
    },

    themeConfig: {
        logo: '/logo.svg',

        nav: [
            { text: 'Guide', link: link('guide/what-is-boost'), activeMatch: '/guide/' },
            {
                text: 'Packages',
                activeMatch: '/packages/',
                items: [
                    { text: 'Which package fits?', link: link('guide/which-package') },
                    {
                        text: 'Applications',
                        items: packageAreas
                            .filter(area => area.base.includes('project-boost'))
                            .map(area => ({ text: area.text, link: link(`${area.base}/index`) })),
                    },
                    {
                        text: 'Composer packages',
                        items: packageAreas
                            .filter(area => area.base.includes('package-boost'))
                            .map(area => ({ text: area.text, link: link(`${area.base}/index`) })),
                    },
                    {
                        text: 'Engine and skills',
                        items: packageAreas
                            .filter(area => area.base.endsWith('boost-core') || area.base.endsWith('boost-skills'))
                            .map(area => ({ text: area.text, link: link(`${area.base}/index`) })),
                    },
                ],
            },
            { text: 'Reference', link: link('reference/cli'), activeMatch: '/reference/' },
            {
                text: 'Links',
                items: [
                    { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/boost-core' },
                    { text: 'Releases', link: 'https://github.com/SanderMuller/boost-core/releases' },
                    { text: 'Changelog', link: 'https://github.com/SanderMuller/boost-core/blob/main/CHANGELOG.md' },
                    { text: 'Upgrading', link: 'https://github.com/SanderMuller/boost-core/blob/main/UPGRADING.md' },
                ],
            },
        ],

        sidebar,

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/boost-core' },
        ],

        docFooter: {
            next: false,
        },

        editLink: {
            pattern: 'https://github.com/SanderMuller/boost-core/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },
})
