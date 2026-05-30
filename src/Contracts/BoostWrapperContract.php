<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Contracts;

/**
 * Wrapper packages (project-boost-laravel, future siblings) declare a class
 * implementing this contract at any PSR-4 prefix they declare in their
 * composer.json, named `BoostWrapper`. Boost-core's cleanup-pass probes the
 * class and excludes the returned emit-path set from "stale boost-emitted
 * output" classification.
 *
 * **Why this contract exists.** Wrapper packages inject vendor skills /
 * guidelines via `SyncEngine::sync()`'s `injectedVendorSkills` and
 * `injectedVendorGuidelines` runtime args. Files emitted from those
 * injections live on disk after a wrapper-driven sync. When `boost sync`
 * runs without the wrapper's injection args (bare-CLI invocation), the
 * resolve pass produces an empty injection set; the cleanup pass then
 * flags every previously-injected file as stale-to-delete. This contract
 * lets wrappers declare their emit surface so bare-CLI sees the files
 * have a backing source and preserves them.
 *
 * **Contract requirements.**
 *
 * - Class MUST be named `BoostWrapper` (case-sensitive) at any PSR-4
 *   prefix the package declares in its `composer.json` `autoload.psr-4`
 *   map. Single-prefix packages have one valid location; multi-prefix
 *   packages may place it under any declared prefix.
 * - `injectedEmitPaths()` MUST be static (zero instantiation surface;
 *   matches Composer plugin discovery patterns).
 * - Return value MUST be a list of strings, each a project-root-relative
 *   path in canonical form (forward slashes, no leading `./`, no `..`,
 *   no duplicate separators, no trailing slash). The engine normalizes
 *   incoming paths anyway, but canonical input keeps wrapper code legible.
 * - Method SHOULD be side-effect-free and fast — the engine calls it
 *   during sync's cleanup-pass, which runs on every sync invocation.
 * - Returned paths SHOULD NOT include guideline files (`CLAUDE.md`,
 *   `AGENTS.md`, `GEMINI.md`). Those use ManagedRegion + operator-tracking
 *   (NOT wholesale file replacement), so they don't fit the contract's
 *   "files that need stale-cleanup-exclusion" surface. The engine filters
 *   guideline-file basenames defensively at the gitignore-pattern emit
 *   point, but the contract intent is that wrappers don't include them.
 *
 * **Failure modes (engine handling).**
 *
 * - Class absent across all PSR-4 prefixes: silent fallback to strict-drift
 *   behavior. No diagnostic. (See 0.11.0 spec §4 + Resolved warning
 *   behavior section for the rationale.)
 * - Class exists but doesn't implement this contract: contract-violation
 *   warning per-package, pinned wording.
 * - `injectedEmitPaths()` throws: exception-safe fallback + warning naming
 *   the exception class.
 * - `injectedEmitPaths()` returns non-array or contains non-string entries:
 *   type-validation warning + skip the wrapper's contribution.
 */
interface BoostWrapperContract
{
    /**
     * Project-root-relative paths the wrapper claims canonical ownership of.
     * Engine excludes the union of all wrappers' returned paths from the
     * stale-file-cleanup pass.
     *
     * @return list<string>
     */
    public static function injectedEmitPaths(string $projectRoot): array;
}
