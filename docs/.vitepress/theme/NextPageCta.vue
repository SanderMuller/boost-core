<script setup lang="ts">
import { computed } from 'vue'
import { useData, withBase } from 'vitepress'
import { areaOf, link, pages } from '../pages'

const { page } = useData()

const currentIndex = computed(() => {
    const current = page.value.relativePath.replace(/\.md$/, '')

    return pages.findIndex(entry => entry.file === current)
})

const card = computed(() => {
    if (currentIndex.value === -1) {
        return undefined
    }

    const current = pages[currentIndex.value]
    const next = pages[currentIndex.value + 1]

    if (next) {
        // Reading order runs through every area, so the label says when the
        // next page leaves the section the reader is in.
        const crossesArea = areaOf(next.file)?.base !== areaOf(current.file)?.base

        return {
            label: crossesArea ? `Next: ${areaOf(next.file)?.text}` : 'Next',
            title: next.text,
            text: next.blurb,
            href: withBase(link(next.file)),
            external: false,
        }
    }

    return {
        label: 'End of the documentation',
        title: 'Something missing?',
        text: 'Open an issue on GitHub and tell us which part of the documentation left you stuck.',
        href: 'https://github.com/SanderMuller/boost-core/issues',
        external: true,
    }
})
</script>

<template>
    <div v-if="card" class="next-page-cta">
        <a
            class="doc-cta-card"
            :href="card.href"
            :target="card.external ? '_blank' : undefined"
            :rel="card.external ? 'noreferrer' : undefined"
        >
            <span class="doc-cta-label">{{ card.label }}</span>
            <span class="doc-cta-title">
                {{ card.title }}
                <span v-if="card.external" class="doc-cta-external" aria-hidden="true">&#8599;</span>
                <span v-if="card.external" class="doc-cta-sr-only"> (opens in a new tab)</span>
            </span>
            <span class="doc-cta-text">{{ card.text }}</span>
        </a>
    </div>
</template>
