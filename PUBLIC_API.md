# Public API

The semver-protected surface of `sandermuller/boost-core`. Anything documented here is covered by semver; anything outside is internal and may change without notice (including in patch releases).

## Versioning

This package follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html). Pre-`1.0.0` releases may break API in MINOR bumps; we surface those in `CHANGELOG.md`.

## Stable surface

### Classes

<!-- List public classes here. Example:
- `SanderMuller\BoostCore\YourPublicClass` — purpose
-->

### Methods

<!-- List public methods on the above classes. Example:
- `YourPublicClass::doThing(string $input): Result`
-->

### Constants

<!-- List public constants. Example:
- `YourPublicClass::DEFAULT_TIMEOUT`
-->

## Experimental surface

- `SanderMuller\BoostCore\Contracts\FileEmitter` — `@experimental`. Plugin seam for vendor packages to emit custom files (e.g. `.mcp.json`) during sync. Will NOT be locked stable until a second non-trivial consumer from a different problem domain validates the shape. See `internal/boost-file-emitter-contract.md` in the design repo for the contract.

## Internal (not covered by semver)

Anything under `SanderMuller\BoostCore\Internal`, anything marked `@internal` in PHPDoc, anything in `src/Support/` and `src/Concerns/`.

## Removed APIs

<!-- Track removed APIs here so consumers know what was removed when. Example:
- `0.5.0` — Removed `OldClass::oldMethod()`. Migrate to `NewClass::newMethod()`.
-->
