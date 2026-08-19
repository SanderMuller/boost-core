<script setup lang="ts">
import { computed } from 'vue'
import { withBase } from 'vitepress'
import { link, pages } from '../pages'

// The reading order a first-time visitor should follow from the home page.
const stepFiles = ['guide/which-package', 'guide/installation', 'guide/how-sync-works']

const steps = computed(() => stepFiles.map((file, index) => {
    const page = pages.find(entry => entry.file === file)

    if (!page) {
        throw new Error(`Home page step "${file}" is missing from the documentation page list.`)
    }

    return { ...page, step: `Step ${index + 1}`, href: withBase(link(page.file)) }
}))
</script>

<template>
    <div class="doc-cta-grid">
        <a v-for="step in steps" :key="step.file" class="doc-cta-card" :href="step.href">
            <span class="doc-cta-label">{{ step.step }}</span>
            <span class="doc-cta-title">{{ step.text }}</span>
            <span class="doc-cta-text">{{ step.blurb }}</span>
        </a>
    </div>

    <div class="doc-cta-footer">
        <a class="doc-cta-button" :href="withBase('/guide/which-package')">Find the package for your project</a>
        <span class="doc-cta-note">
            Shipping your own skill bundle? Start at
            <a :href="withBase('/packages/boost-core/')">boost-core</a>.
        </span>
    </div>
</template>
