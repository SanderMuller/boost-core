# Changelog

All notable changes to `sandermuller/boost-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/sandermuller/boost-core/compare/0.1.1...HEAD)

### Added

- Initial scaffolding via `sandermuller/repo-init` (php-package category, customized to `composer-plugin` type).
- Composer plugin entry (`BoostCorePlugin`) registering a `CommandProvider` for future `boost:*` commands.
- Standalone `bin/boost` entry script for direct invocation outside Composer.
- `FileEmitter` plugin contract sketch (`@experimental`) — see `internal/boost-file-emitter-contract.md` in the design repo.

## [0.1.1](https://github.com/sandermuller/boost-core/compare/0.1.0...0.1.1) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/compare/0.1.0...0.1.1

## [0.1.0](https://github.com/sandermuller/boost-core/compare/...0.1.0) - 2026-05-18

**Full Changelog**: https://github.com/SanderMuller/boost-core/commits/0.1.0
