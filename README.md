# boost-core

> AI agent configuration sync for PHP projects. Author skills and guidelines once in `.ai/`, publish to nine agents (Claude Code, Cursor, Copilot, Codex, Gemini, Junie, Kiro, OpenCode, Amp). Framework-free PHP, allowlist-based vendor trust, Rector-style explicit commands.

## Install

You don't usually install `boost-core` directly — it comes in as a dep of one of the bundle packages that match your role:

```bash
# PHP application developer
composer require --dev sandermuller/project-boost

# Framework-agnostic Composer package author
composer require --dev sandermuller/package-boost-php

# Laravel package author
composer require --dev sandermuller/package-boost-laravel
```

Direct install for tooling authors who want to ship their own skill bundle:

```bash
composer require --dev "sandermuller/boost-core:^1.0@dev"
```

## Usage

```bash
composer boost:init      # generate boost.php starter
composer boost:install   # interactive picker: agents + vendor allowlist
composer boost:sync      # fan out to selected agents
```

For tooling authors who want to publish their own skills to every AI agent on the user's machine:

```bash
composer boost:sync --scope=user   # ~/.{agent}/skills/<package>/<skill>.md
```

See [`RELEASING.md`](RELEASING.md) for the publish path. The FileEmitter plugin contract is `@experimental` — the shape will change before v1.0 stable.

## License

MIT. See [LICENSE](LICENSE).
