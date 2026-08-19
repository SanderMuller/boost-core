---
layout: home

hero:
  name: boost for PHP
  text: One .ai/ source, ten agents
  tagline: Write skills, guidelines, and slash commands once. boost publishes them to Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp, and Antigravity. Any PHP project, Laravel or not.
  image:
    src: /logo.svg
    alt: boost
  actions:
    - theme: brand
      text: Get started
      link: /guide/installation
    - theme: alt
      text: Why boost?
      link: /guide/why-boost
    - theme: alt
      text: GitHub
      link: https://github.com/SanderMuller/boost-core

features:
  - title: One source, every agent
    details: Add an agent to withAgents() and its files appear on the next sync. You never maintain a per-agent copy of the same skill.
    link: /guide/how-sync-works
  - title: Skills shipped as Composer packages
    details: Any package shipping resources/boost/skills/ becomes a skill source once you allowlist its vendor. Author a team's skill set once, require it everywhere.
    link: /guide/skill-sources
  - title: Scoped per project
    details: Tag filtering keeps a jira-triage skill out of repositories with no Jira work, including out of the agent's skill-selection index, where every unwanted description costs attention.
    link: /guide/tags-and-dependencies
  - title: Skills that depend on skills
    details: boost-requires makes a hand-off ship as a unit. A dependency your tags would have dropped is rescued, so a flow never delegates to a skill that is not there.
    link: /guide/tags-and-dependencies#rescue
  - title: Nothing hand-written gets clobbered
    details: boost tracks what it owns in a manifest. Adopting it in a repository that already has a CLAUDE.md does not wipe that file.
    link: /guide/file-ownership
  - title: Works alongside laravel/boost
    details: laravel/boost keeps the MCP server and the docs API. project-boost-laravel owns the cross-agent fan-out. One command sequence keeps both from overwriting the other.
    link: /guide/laravel-coexistence
---

## Pick the package for your project

You install one family package. It pulls the engine in for you.

| You are building | Install |
|---|---|
| A PHP application | [`sandermuller/project-boost-php`](/packages/project-boost-php/) |
| A Laravel application | [`sandermuller/project-boost-laravel`](/packages/project-boost-laravel/) |
| A framework-agnostic Composer package | [`sandermuller/package-boost-php`](/packages/package-boost-php/) |
| A Laravel package | [`sandermuller/package-boost-laravel`](/packages/package-boost-laravel/) |
| Your own skill bundle or tooling | [`sandermuller/boost-core`](/packages/boost-core/) |

```bash
composer require --dev sandermuller/project-boost-php
vendor/bin/boost install   # pick agents, vendors, and tags
vendor/bin/boost sync      # fan out
```

## Where to next

<HomeNextSteps />
