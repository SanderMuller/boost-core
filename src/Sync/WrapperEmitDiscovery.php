<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use JsonException;
use ReflectionClass;
use SanderMuller\BoostCore\Contracts\BoostWrapperContract;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use Throwable;

/**
 * Discovers wrapper packages declaring `BoostWrapper` classes (0.11.0). For
 * each package in `InstalledPackages`, probes the PSR-4 prefixes declared in
 * the package's `composer.json` for a `BoostWrapper` class implementing
 * `BoostWrapperContract`, and unions the returned `injectedEmitPaths()`
 * across all wrappers.
 *
 * The cleanup pass in `SyncEngine::cleanupStaleManagedFiles()` uses the
 * resulting path set to exclude wrapper-claimed files from "stale boost-
 * emitted output to delete" classification — fixes the bare-CLI false-
 * positive drift behavior on wrapper-installed projects.
 *
 * Failure-mode handling (per 0.11.0 spec Resolved warning-behavior section):
 *  - Class absent across all PSR-4 prefixes: silent — no diagnostic.
 *  - Class exists but does NOT implement `BoostWrapperContract`: per-package
 *    contract-violation warning, pinned wording.
 *  - `injectedEmitPaths()` throws: per-package exception warning naming the
 *    exception class + first line of message.
 *  - `injectedEmitPaths()` returns non-array or contains non-string entries:
 *    per-package type-validation warning.
 */
final readonly class WrapperEmitDiscovery
{
    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * @param  list<string>  $activeAgents  agent enum values in the project's
     *   `withAgents(...)` set. Passed through to each wrapper's
     *   `injectedEmitPaths()` so wrappers can compute the correct emit paths
     *   for the active agent layout (`.claude/skills/` vs `.agents/skills/`
     *   vs `.github/skills/` etc.) using boost-core's `AgentTarget` API,
     *   instead of guessing or over-claiming across all agents.
     * @return array{paths: array<string, string>, diagnostics: list<Diagnostic>}
     *   `paths` maps each wrapper-claimed emit path → the OWNING wrapper package
     *   name (e.g. `sandermuller/project-boost-laravel`), so the manifest writer
     *   can record `provenance: wrapper:<vendor/package>`. Callers that only
     *   need membership use the keys / `isset()`.
     */
    public function discover(string $projectRoot, array $activeAgents): array
    {
        /** @var array<string, string> $excludedPaths  path → owning wrapper package */
        $excludedPaths = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        foreach ($this->packages->all() as $package) {
            $prefixes = $this->readPsr4Prefixes($package->installPath);
            if ($prefixes === []) {
                continue;
            }

            $resolution = $this->resolveWrapperClass($package->name, $package->installPath, $prefixes);
            if ($resolution['class'] === null) {
                if ($resolution['diagnostic'] instanceof Diagnostic) {
                    $diagnostics[] = $resolution['diagnostic'];
                }

                continue;
            }

            $callResult = $this->callInjectedEmitPaths($package->name, $resolution['class'], $projectRoot, $activeAgents);
            if ($callResult['diagnostic'] instanceof Diagnostic) {
                $diagnostics[] = $callResult['diagnostic'];

                continue;
            }

            foreach ($callResult['paths'] as $path) {
                // path → owning wrapper package (last writer wins on the rare
                // cross-wrapper path collision; membership is unaffected).
                $excludedPaths[$path] = $package->name;
            }
        }

        return [
            'paths' => $excludedPaths,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Read the package's `autoload.psr-4` prefixes in declared order.
     *
     * @return list<string>
     */
    private function readPsr4Prefixes(string $installPath): array
    {
        $composerJsonPath = $installPath . '/composer.json';
        if (! is_file($composerJsonPath)) {
            return [];
        }

        $raw = @file_get_contents($composerJsonPath);
        if ($raw === false) {
            return [];
        }

        try {
            /** @var array<mixed, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $autoload = $decoded['autoload'] ?? null;
        if (! is_array($autoload)) {
            return [];
        }

        $psr4 = $autoload['psr-4'] ?? null;
        if (! is_array($psr4)) {
            return [];
        }

        /** @var list<string> $prefixes in declared order — multi-prefix packages need this for the prefer-implementing-candidate algorithm */
        $prefixes = [];
        foreach (array_keys($psr4) as $prefix) {
            // Skip the empty-string PSR-4 fallback. `class_exists('BoostWrapper')`
            // would resolve the GLOBAL namespace via the autoloader, and any
            // unrelated package shipping a global `BoostWrapper` would then be
            // attributed to whichever empty-prefix package is inspected. The
            // file-path scope check below cannot disambiguate a genuine
            // global-namespace collision (one FQN, one class, per process).
            // Wrappers MUST declare their class under an explicit (non-empty)
            // PSR-4 prefix — documented in BoostWrapperContract.
            if (is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * Probe each declared PSR-4 prefix for a `<prefix>BoostWrapper` class.
     * Prefer a contract-implementing candidate over a violating one. Verify
     * the resolved class file lives under the package's installPath so a
     * foreign class occupying the same FQN (prefix collision) is not
     * misattributed to this package (codex-review pin).
     *
     * Identical-FQN collisions across two installed wrappers (same non-empty
     * prefix + same `BoostWrapper` class name) are unrepresentable in PHP
     * — one FQN maps to one class per process. The autoloader resolves the
     * first; the file-path check then attributes it to its owning package
     * and the colliding package is treated as wrapper-less. This is the only
     * PHP-representable behavior; documented as a limitation.
     *
     * Failure modes (per spec Resolved warning-behavior section):
     *  - class absent across all prefixes → silent (no diagnostic)
     *  - autoload (parse error / top-level throw) → autoload-failure warning,
     *    surfaced only if no valid candidate found under a later prefix
     *  - class present but doesn't implement the contract → contract-violation
     *
     * @param  list<string>  $prefixes
     * @return array{class: ?class-string<BoostWrapperContract>, diagnostic: ?Diagnostic}
     */
    private function resolveWrapperClass(string $packageName, string $installPath, array $prefixes): array
    {
        $nonImplementingCandidate = null;
        $autoloadFailure = null;

        foreach ($prefixes as $prefix) {
            $fqn = $prefix . 'BoostWrapper';

            // class_exists() triggers autoload. Guard parse errors / top-level
            // throws so one broken wrapper doesn't abort the entire sync.
            // Keep probing later prefixes after a failure: a multi-prefix
            // package could have a broken class under one prefix and a valid
            // one under another. The autoload-failure diagnostic surfaces only
            // if NO valid candidate is found across all prefixes.
            try {
                $exists = class_exists($fqn);
            } catch (Throwable $throwable) {
                $autoloadFailure ??= sprintf(
                    "Package `%s`'s `%s` class could not be autoloaded (`%s`: %s). Falling back to strict drift comparison for this wrapper. To resolve: report the autoload error to `%s`'s maintainer or upgrade the wrapper.",
                    $packageName,
                    $fqn,
                    $throwable::class,
                    $this->firstLine($throwable->getMessage()),
                    $packageName,
                );

                continue;
            }

            if (! $exists) {
                continue;
            }

            // Package-scope the match: the resolved class file must live under
            // this package's installPath. Filters foreign classes occupying
            // the same FQN.
            if (! $this->classBelongsToPackage($fqn, $installPath)) {
                continue;
            }

            if (is_subclass_of($fqn, BoostWrapperContract::class)) {
                /** @var class-string<BoostWrapperContract> $fqn */
                return ['class' => $fqn, 'diagnostic' => null];
            }

            $nonImplementingCandidate ??= $fqn;
        }

        // No valid implementing candidate. Prefer the most actionable
        // diagnostic: contract-violation (class present, wrong shape) is more
        // specific than a load failure.
        if ($nonImplementingCandidate !== null) {
            // Pinned wording — wording-revert-as-regression-test pattern.
            // See 0.11.0 spec Resolved warning-behavior section.
            return [
                'class' => null,
                'diagnostic' => Diagnostic::warning(
                    null,
                    sprintf(
                        "Package `%s` declares a `%s` class but it does not implement `%s`. Falling back to strict drift comparison for this wrapper (false positives possible on its injected paths). To resolve: implement the contract, or report the contract-violation to `%s`'s maintainer.",
                        $packageName,
                        $nonImplementingCandidate,
                        BoostWrapperContract::class,
                        $packageName,
                    ),
                    $packageName,
                ),
            ];
        }

        if ($autoloadFailure !== null) {
            return ['class' => null, 'diagnostic' => Diagnostic::warning(null, $autoloadFailure, $packageName)];
        }

        return ['class' => null, 'diagnostic' => null];
    }

    /**
     * Verify the resolved class file lives inside the package's installPath.
     * Filters a foreign class occupying the same FQN (e.g. via a colliding
     * PSR-4 prefix declared by another installed package) — without this,
     * discovery could attribute that class to the inspected package and
     * preserve/delete the wrong paths or warn about the wrong dependency.
     *
     * @param  class-string  $fqn
     */
    private function classBelongsToPackage(string $fqn, string $installPath): bool
    {
        // Caller has already confirmed class_exists($fqn) before this call;
        // ReflectionClass cannot throw here.
        $reflection = new ReflectionClass($fqn);
        $file = $reflection->getFileName();
        if ($file === false) {
            return false;
        }

        $resolvedFile = realpath($file);
        $resolvedInstall = realpath($installPath);
        if ($resolvedFile === false || $resolvedInstall === false) {
            return false;
        }

        return str_starts_with($resolvedFile, $resolvedInstall . DIRECTORY_SEPARATOR);
    }

    /**
     * Indirect dispatch so PHPStan can't constant-fold the contract's declared
     * `list<string>` return type. Runtime implementors can violate the
     * contract; the engine MUST type-check the result. Without this
     * indirection PHPStan reports the `is_array()` + per-entry `is_string()`
     * checks as dead code.
     *
     * @param  class-string<BoostWrapperContract>  $class
     * @param  list<string>  $activeAgents
     */
    private function invokeUntyped(string $class, string $projectRoot, array $activeAgents): mixed
    {
        return call_user_func([$class, 'injectedEmitPaths'], $projectRoot, $activeAgents);
    }

    private function firstLine(string $message): string
    {
        $strtok = strtok($message, "\n");

        return trim($strtok === false ? '' : $strtok);
    }

    /**
     * @param  class-string<BoostWrapperContract>  $class
     * @param  list<string>  $activeAgents
     * @return array{paths: list<string>, diagnostic: ?Diagnostic}
     */
    private function callInjectedEmitPaths(string $packageName, string $class, string $projectRoot, array $activeAgents): array
    {
        try {
            $result = $this->invokeUntyped($class, $projectRoot, $activeAgents);
        } catch (Throwable $throwable) {
            return [
                'paths' => [],
                'diagnostic' => Diagnostic::warning(
                    null,
                    sprintf(
                        "Wrapper `%s`'s `BoostWrapper::injectedEmitPaths()` threw `%s`: %s. Falling back to strict drift comparison for this wrapper. To resolve: report the exception to `%s`'s maintainer or upgrade the wrapper.",
                        $packageName,
                        $throwable::class,
                        $this->firstLine($throwable->getMessage()),
                        $packageName,
                    ),
                    $packageName,
                ),
            ];
        }

        if (! is_array($result)) {
            return [
                'paths' => [],
                'diagnostic' => $this->wrongTypeDiagnostic($packageName, 'non-array (' . get_debug_type($result) . ')'),
            ];
        }

        $normalized = [];
        foreach ($result as $entry) {
            if (! is_string($entry)) {
                return [
                    'paths' => [],
                    'diagnostic' => $this->wrongTypeDiagnostic(
                        $packageName,
                        'array with non-string entry (' . get_debug_type($entry) . ')',
                    ),
                ];
            }

            $canonical = $this->canonicalizePath($entry);
            if ($canonical !== null) {
                $normalized[] = $canonical;
            }
        }

        return ['paths' => $normalized, 'diagnostic' => null];
    }

    private function wrongTypeDiagnostic(string $packageName, string $actualDescription): Diagnostic
    {
        return Diagnostic::warning(
            null,
            sprintf(
                "Wrapper `%s`'s `BoostWrapper::injectedEmitPaths()` returned an invalid value (expected list of strings, got %s). Falling back to strict drift comparison for this wrapper. To resolve: report the return-type bug to `%s`'s maintainer or upgrade the wrapper.",
                $packageName,
                $actualDescription,
                $packageName,
            ),
            $packageName,
        );
    }

    /**
     * Normalize to canonical project-root-relative form:
     *  - forward slashes only
     *  - no leading `./` or `/`
     *  - no duplicate separators
     *  - no trailing slash
     *  - no `..` segments (rejected — returns null)
     *
     * Engine applies the same normalization to its own emitted-path set
     * before union comparison; identical input → identical output.
     */
    private function canonicalizePath(string $raw): ?string
    {
        $normalized = str_replace('\\', '/', $raw);

        // Segment-based normalization so embedded `.` segments collapse too
        // (`.agents/skills/foo/./SKILL.md` → `.agents/skills/foo/SKILL.md`).
        // A leading-only strip would leave mid-path `.` segments intact,
        // and the cleanup-pass comparison uses canonical on-disk paths —
        // so a non-collapsed claim would never match and the file would be
        // falsely flagged stale (codex-review pin). `..` anywhere is
        // rejected (wrappers MUST stay inside project root).
        $out = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $out[] = $segment;
        }

        if ($out === []) {
            return null;
        }

        return implode('/', $out);
    }
}
