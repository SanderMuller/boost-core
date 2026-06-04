<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use JsonException;

/**
 * The sync ownership manifest — an external record of every file
 * boost-core EMITTED, written to `.boost/manifest.json` (gitignored). It gives
 * the engine the ownership state it otherwise lacks: with a manifest, boost
 * KNOWS which on-disk files it wrote, so it can safely clear/prune its own
 * output while NEVER touching operator-authored content — replacing
 * "guess from content" with "consult the manifest".
 *
 * Ownership rules:
 *  - **guidance** (`CLAUDE.md`/`AGENTS.md`/`GEMINI.md`): owned IFF listed AND the
 *    on-disk sha matches the recorded sha (unchanged since boost wrote it). A
 *    sha mismatch means the operator hand-edited it → treat as operator-owned
 *    (never clear/blank — never-lossy).
 *  - **skill / command**: fully generated, owned IFF listed (sha is
 *    change-detection only, not an ownership gate).
 *
 * Provenance tags each path `engine` (boost-core-native) or
 * `wrapper:<vendor/package>` (emitted via a `BoostWrapper` injected set), so a
 * later bare-CLI run can preserve wrapper paths without re-probing the contract.
 *
 * INVARIANT (load-bearing): the manifest lists ONLY emission targets — never
 * source dirs (`.ai/`, `resources/boost/`). boost only ever WRITES emission
 * targets, so this holds by construction; `withEntry()` rejects source paths
 * defensively to protect dual-role publisher repos whose shipped product lives
 * under `resources/boost/`.
 *
 * Backward-safety: an absent OR corrupt manifest decodes to {@see empty()} — no
 * ownership is asserted, so the engine falls back to the conservative behavior
 * (no clearing/pruning).
 *
 * @phpstan-type ManifestEntry array{sha256: string, category: string, provenance: string, scope: string}
 *
 * @internal
 */
final readonly class SyncManifest
{
    public const RELATIVE_PATH = '.boost/manifest.json';

    public const DIR = '.boost';

    /**
     * The runtime-state dir when the project's `boost.php` lives under `.config/`
     * — the manifest moves to `.config/boost/` so all boost artifacts group under
     * `.config/` (tracked `.config/boost.php` + gitignored `.config/boost/`).
     */
    public const CONFIG_DIR = '.config/boost';

    public const VERSION = 1;

    public const PROVENANCE_ENGINE = 'engine';

    /**
     * Provenance prefix for FileEmitter outputs — `emitter:<fqcn>`.
     * Parallels `wrapper:<vendor/package>`. Lets the reconcile-on-sync reap
     * attribute a prior-recorded emitter output back to its producing emitter,
     * so a DISABLED / errored emitter's file is preserved (not reaped) while a
     * genuinely dormant emitter's file is reaped. Engine-family for prune
     * purposes (the engine runs emitters during sync, so bare-CLI reproduces
     * the set — unlike wrapper paths).
     */
    public const PROVENANCE_EMITTER_PREFIX = 'emitter:';

    /** FileEmitter output — fully generated, owned IFF listed. */
    public const CATEGORY_FILE = 'file';

    public const CATEGORY_GUIDANCE = 'guidance';

    private const SOURCE_PREFIXES = ['.ai/', 'resources/boost/'];

    /**
     * @param  array<string, ManifestEntry>  $entries  keyed by project-relative path
     */
    private function __construct(public array $entries) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The manifest dir for a config layout — `.config/boost` when `boost.php`
     * lives under `.config/`, else the historical root `.boost`.
     */
    public static function dirFor(bool $inConfigDir): string
    {
        return $inConfigDir ? self::CONFIG_DIR : self::DIR;
    }

    /**
     * The project-relative manifest file path for a config layout.
     */
    public static function relativePathFor(bool $inConfigDir): string
    {
        return self::dirFor($inConfigDir) . '/manifest.json';
    }

    /**
     * The managed-gitignore pattern for the ACTIVE runtime manifest dir
     * (`.config/boost/` on the `.config/` layout, else `.boost/`). Added when a
     * manifest/ledger actually lives there this sync. enumerateManagedFiles skips
     * it (engine-internal state), so the line is pure-ignore, never a cleanup target.
     */
    public static function activeIgnorePatternFor(bool $inConfigDir): string
    {
        return self::dirFor($inConfigDir) . '/';
    }

    /**
     * The PERMANENT legacy-dir ignore pattern(s) — independent of whether this sync
     * writes a manifest. On the `.config/` layout the legacy root `.boost/` is kept
     * ignored forever so a teammate who pulls a freshly-migrated repo before their
     * own sync never sees a stale (never-tracked) root `.boost/manifest.json` surface
     * as untracked — even when the migrated project currently owns nothing and emits
     * no manifest. Empty on the root layout (there `.boost/` IS the active dir).
     *
     * @return list<string>
     */
    public static function legacyIgnorePatternsFor(bool $inConfigDir): array
    {
        return $inConfigDir ? [self::DIR . '/'] : [];
    }

    /**
     * Load the prior manifest from a project root. Absent or corrupt → empty
     * (backward-safe: no ownership asserted).
     *
     * Reads the manifest for the ACTIVE layout first, falling back to the OTHER
     * layout's manifest when the active one is absent — so a project migrating
     * EITHER direction (root ↔ `.config/`) carries its prior ownership forward (the
     * first post-migration sync then rewrites the manifest at the active location
     * and prunes the stale one). The first EXISTING candidate is authoritative: a
     * present-but-corrupt active manifest yields empty without silently reading the
     * other layout's stale copy.
     */
    public static function fromProjectRoot(string $projectRoot, bool $inConfigDir = false): self
    {
        $root = rtrim($projectRoot, '/');
        $candidates = $inConfigDir
            ? [$root . '/' . self::relativePathFor(true), $root . '/' . self::RELATIVE_PATH]
            : [$root . '/' . self::RELATIVE_PATH, $root . '/' . self::relativePathFor(true)];

        $raw = false;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $raw = @file_get_contents($candidate);
                break;
            }
        }

        if ($raw === false) {
            return self::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::empty();
        }

        if (! is_array($decoded) || ! isset($decoded['emitted']) || ! is_array($decoded['emitted'])) {
            return self::empty();
        }

        /** @var array<string, ManifestEntry> $entries */
        $entries = [];
        /** @var mixed $entry */
        foreach ($decoded['emitted'] as $entryPath => $entry) {
            if (! is_string($entryPath)) {
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            if (! isset($entry['sha256'])) {
                continue;
            }

            if (! is_string($entry['sha256'])) {
                continue;
            }

            $entries[$entryPath] = [
                'sha256' => $entry['sha256'],
                'category' => isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : 'unknown',
                'provenance' => isset($entry['provenance']) && is_string($entry['provenance']) ? $entry['provenance'] : self::PROVENANCE_ENGINE,
                'scope' => isset($entry['scope']) && is_string($entry['scope']) ? $entry['scope'] : 'project',
            ];
        }

        return new self($entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function has(string $relativePath): bool
    {
        return isset($this->entries[$relativePath]);
    }

    /**
     * Is this guidance file boost-owned? Requires listed AND the current
     * on-disk sha matching the recorded sha (unchanged since boost wrote it).
     * A divergence means the operator edited it → NOT owned for clear/blank.
     */
    public function ownsGuidance(string $relativePath, string $currentSha): bool
    {
        return isset($this->entries[$relativePath])
            && $this->entries[$relativePath]['sha256'] === $currentSha;
    }

    /**
     * Provenance of a listed path, or null if not listed.
     */
    public function provenanceOf(string $relativePath): ?string
    {
        return $this->entries[$relativePath]['provenance'] ?? null;
    }

    public function isEngineProvenance(string $relativePath): bool
    {
        return ($this->entries[$relativePath]['provenance'] ?? null) === self::PROVENANCE_ENGINE;
    }

    /**
     * Add (or replace) an emitted entry. Rejects source-dir paths defensively
     * (the invariant) — they must never be manifest-listed or prune candidates.
     */
    public function withEntry(string $relativePath, string $sha256, string $category, string $provenance, string $scope = 'project'): self
    {
        foreach (self::SOURCE_PREFIXES as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return $this;
            }
        }

        $entries = $this->entries;
        $entries[$relativePath] = [
            'sha256' => $sha256,
            'category' => $category,
            'provenance' => $provenance,
            'scope' => $scope,
        ];

        return new self($entries);
    }

    /**
     * Serialize to the on-disk JSON shape (Composer-style formatting).
     */
    public function toJson(string $generatedBy): string
    {
        $entries = $this->entries;
        ksort($entries);

        return json_encode([
            'version' => self::VERSION,
            'generatedBy' => $generatedBy,
            'emitted' => $entries === [] ? (object) [] : $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
