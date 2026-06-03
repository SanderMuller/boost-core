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
 * **Computing the emit paths for the active agents.** `$activeAgents` is the
 * list of agent enum values (`Agent::value`) in the project's
 * `withAgents(...)` set. Boost-core emits each injected skill into a
 * different directory per agent — `.claude/skills/<name>/SKILL.md` for
 * Claude Code, the shared `.agents/skills/<name>/SKILL.md` pool for
 * Cursor / Copilot / Codex / Amp / Junie / Kiro / OpenCode, etc. A wrapper
 * computes the correct claim set by mapping each injected skill name across
 * the active agents' skill directories. Use boost-core's own `AgentTarget`
 * implementations (public API) to resolve each agent's skill-dir layout
 * rather than hard-coding paths — this keeps the wrapper in lockstep with
 * boost-core's emit layout across versions.
 *
 * **Scope: stale-file-cleanup exclusion only.** This contract closes the
 * bare-CLI *deletion* false-positive (standalone emitted files flagged
 * stale). It does NOT regenerate wrapper-injected *content* on a bare CLI
 * run — managed-region guideline content injected via
 * `injectedVendorGuidelines` is not reproducible without the wrapper's
 * runtime injection args, by construction. Bare-CLI runs that need the full
 * injected content must use the wrapper's canonical entry point (e.g.
 * `php artisan project-boost:sync`); boost-core's `boost doctor`
 * entry-point-mismatch banner points operators there.
 *
 * **Failure modes (engine handling).**
 *
 * - Class absent across all PSR-4 prefixes: silent fallback to strict-drift
 *   behavior. No diagnostic.
 * - Class exists but doesn't implement this contract: contract-violation
 *   warning per-package, pinned wording.
 * - `injectedEmitPaths()` throws: exception-safe fallback + warning naming
 *   the exception class.
 * - `injectedEmitPaths()` returns non-array or contains non-string entries:
 *   type-validation warning + skip the wrapper's contribution.
 *
 * @api
 */
interface BoostWrapperContract
{
    /**
     * Project-root-relative paths the wrapper claims canonical ownership of.
     * Engine excludes the union of all wrappers' returned paths from the
     * stale-file-cleanup pass.
     *
     * @param  list<string>  $activeAgents  agent enum values (`Agent::value`)
     *   in the project's `withAgents(...)` set. Use these to compute the
     *   per-agent emit paths for the injected skills (see the class-level
     *   docblock for how to resolve each agent's skill-dir layout).
     * @return list<string>
     */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array;
}
