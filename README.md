# boost-core

> AI agent configuration sync for PHP projects. Author skills and guidelines once, publish to every agent.

**Status:** Under construction. Not yet usable.

See the design docs in the companion `elements` repo at `internal/boost-architecture-plan.md` for design rationale.

## Goals

- Author skills and guidelines once in `.ai/`, publish to nine AI agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp).
- Vendor packages can ship their own skills/guidelines for opt-in adoption via an allowlist trust model.
- Framework-free PHP — runs on any project (no Laravel runtime dep).
- Rector-style operation: explicit commands, no Composer lifecycle hooks, generated files committed to git.

## Installation

Coming soon. Will be installed transitively via one of:

- `sandermuller/project-boost` (PHP application devs)
- `sandermuller/package-boost-php` (framework-agnostic Composer package authors)
- `sandermuller/package-boost-laravel` (Laravel package authors)

## License

MIT. See [LICENSE](LICENSE).
