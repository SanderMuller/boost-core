<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncManifest;

/**
 * The conventions build for one sync transaction (0.15.0 inlining / 0.16.0
 * observability), extracted from SyncEngine (maintenance cycle 2026-05).
 *
 * Discovers the conventions schema ONCE and builds the collaborators the sync
 * needs: the slot {@see ConventionsInliner} (run over vendor + host skills AND
 * assembled guidance), the rendered `## Project Conventions` `section` (null when
 * no allowlisted vendor ships a schema; the drop-gate later decides whether it is
 * actually written), discovery `diagnostics`, and the {@see ConventionTokenLeakScanner}.
 *
 * This is the single conventions-build authority — `ConventionTokenLeakScanner::
 * fromConfig()` delegates here, so the build is defined once (previously this
 * logic was duplicated between SyncEngine::conventionsContext and that factory).
 */
final readonly class ConventionsPass
{
    /**
     * @param  list<Diagnostic>  $diagnostics  schema discovery + validation diagnostics
     */
    private function __construct(
        private ConventionsInliner $inliner,
        private SlotResolver $resolver,
        private ?string $section,
        private array $diagnostics,
    ) {}

    public static function build(InstalledPackages $packages, BoostConfig $config): self
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        ['sources' => $sources, 'diagnostics' => $convDiagnostics] = (new SchemaDiscovery($packages))->discover(
            $config->allowedVendors,
            conventionsDeclared: $config->conventions !== [],
        );
        $diagnostics = [...$diagnostics, ...$convDiagnostics];

        /** @var array<string, mixed> $composed */
        $composed = [];
        $section = null;
        if ($sources !== []) {
            $schema = new ConventionsSchema($sources);
            $diagnostics = [...$diagnostics, ...$schema->validate($config->conventions)];
            $composed = $schema->compose();
            $seed = (new ConventionsBlockEmitter())->scaffoldSeed($sources);
            $section = (new GuidanceComposer())->renderConventionsSection($config->conventions, $seed);
        }

        /** @var list<string> $slotRoots */
        $slotRoots = [];
        $properties = $composed['properties'] ?? null;
        if (is_array($properties)) {
            foreach (array_keys($properties) as $root) {
                if (is_string($root) && $root !== 'schema-version') {
                    $slotRoots[] = $root;
                }
            }
        }

        $resolver = new SlotResolver($config->conventions, $composed);

        return new self(new ConventionsInliner($resolver, $slotRoots), $resolver, $section, $diagnostics);
    }

    public function inliner(): ConventionsInliner
    {
        return $this->inliner;
    }

    /**
     * The rendered `## Project Conventions` block, or null when no allowlisted
     * vendor ships a schema. The drop-gate decides whether it is actually written.
     */
    public function section(): ?string
    {
        return $this->section;
    }

    /**
     * @return list<Diagnostic>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function leakScanner(): ConventionTokenLeakScanner
    {
        return new ConventionTokenLeakScanner($this->inliner, $this->resolver);
    }

    /**
     * Inline conventions tokens into each skill body, plus the 0.16.0 per-skill
     * self-check (positional warnings for a token left raw). `requiresRuntime` is
     * true if ANY skill still needs the rendered block; `errors` are render-class
     * token errors (fail --check); `inlinedAny` is positive proof of migration.
     *
     * @param  list<Skill>  $skills
     * @return array{skills: list<Skill>, requiresRuntime: bool, errors: list<string>, inlinedAny: bool, selfCheck: list<Diagnostic>}
     */
    public function inlineSkills(array $skills): array
    {
        $out = [];
        $requiresRuntime = false;
        $inlinedAny = false;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<Diagnostic> $selfCheck */
        $selfCheck = [];

        foreach ($skills as $skill) {
            $result = $this->inliner->inline($skill->body);
            if ($result->requiresRuntimeConventions) {
                $requiresRuntime = true;
            }

            if ($result->inlinedAny) {
                $inlinedAny = true;
            }

            $errors = [...$errors, ...$result->errors];
            $out[] = $skill->withBody($result->body);
            $selfCheck = [...$selfCheck, ...$this->selfCheck('skill: ' . $skill->name, $result->body)];
        }

        return ['skills' => $out, 'requiresRuntime' => $requiresRuntime, 'errors' => $errors, 'inlinedAny' => $inlinedAny, 'selfCheck' => $selfCheck];
    }

    /**
     * Inline slot tokens into each guidance body + decide the conventions-block
     * drop gate (§2). The block is KEPT when ANY live artifact still needs it: a
     * skill (`skillRequiresRuntime`), a guidance body with a legacy `$.slot` ref /
     * unresolved token, or a guidance body that still POINTS at the section
     * (heading-relative prose — §3b, conservative keep). It DROPS only on positive
     * proof of full migration (something inlined, nothing needs runtime, no token
     * errored). `errors` are guidance-local render-class token errors.
     *
     * @param  array<string, array{body: string, isClaude: bool}>  $guidanceFiles
     * @return array{files: array<string, array{body: string, isClaude: bool}>, section: ?string, errors: list<string>, selfCheck: list<Diagnostic>}
     */
    public function inlineGuidanceAndGate(string $projectRoot, array $guidanceFiles, bool $skillRequiresRuntime, bool $skillInlinedAny, SyncManifest $priorManifest): array
    {
        $guidanceRequiresRuntime = false;
        $anyInlined = $skillInlinedAny;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<Diagnostic> $selfCheck */
        $selfCheck = [];
        foreach ($guidanceFiles as $file => $info) {
            $result = $this->inliner->inline($info['body']);
            $guidanceFiles[$file]['body'] = $result->body;
            $errors = [...$errors, ...$result->errors];
            $selfCheck = [...$selfCheck, ...$this->selfCheck($file, $result->body)];
            if ($result->inlinedAny) {
                $anyInlined = true;
            }

            // KEEP the block if the emitted body needs runtime resolution OR
            // depends on the section by prose pointer (codex P1.2) OR the EXISTING
            // on-disk content (which migrate() may preserve as residual) carries a
            // legacy ref / pointer (codex P1.1). Fail toward keep. Scan only the
            // content that will SURVIVE this sync (codex round-5): a file boost
            // owns is regenerated (scan its surviving residual, or nothing for
            // owned-markerless); a file boost doesn't own is preserved wholesale
            // (scan it). Strips boost's own rendered block either way (round-3).
            $existing = is_file($projectRoot . '/' . $file) ? (string) @file_get_contents($projectRoot . '/' . $file) : '';
            $boostOwns = $existing !== '' && $priorManifest->ownsGuidance($file, hash('sha256', $existing));
            $existingResidual = $this->survivingGuidanceForGate($existing, $boostOwns);
            if ($result->requiresRuntimeConventions
                || $this->inliner->dependsOnConventions($result->body)
                || $this->inliner->dependsOnConventions($existingResidual)
            ) {
                $guidanceRequiresRuntime = true;
            }
        }

        // Drop ONLY on positive proof of full migration; otherwise KEEP (a
        // pure-conventions project with no token skills renders the block exactly
        // as pre-0.15, backward-safe; any uncertainty fails toward keep).
        $fullyMigrated = $anyInlined && ! $skillRequiresRuntime && ! $guidanceRequiresRuntime && $errors === [];
        $effectiveSection = ($this->section !== null && ! $fullyMigrated) ? $this->section : null;

        return ['files' => $guidanceFiles, 'section' => $effectiveSection, 'errors' => $errors, 'selfCheck' => $selfCheck];
    }

    /**
     * 0.16.0 self-check: warning-level diagnostics for a token left RAW in a
     * freshly-rendered body, made positional with a `<label>:<line>` locator.
     * Reuses {@see ConventionsInliner::scanLeaks()} so sync-time, doctor, and
     * validate classify identically. Warnings only — the gate stays the recorded
     * token errors. On a healthy render it emits nothing.
     *
     * @return list<Diagnostic>
     */
    private function selfCheck(string $label, string $body): array
    {
        $out = [];
        foreach ($this->inliner->scanLeaks($body) as $hit) {
            $where = $label . ':' . $hit->line;
            $out[] = Diagnostic::warning($hit->path, $hit->kind === LeakHit::KIND_FENCE_OPENER
                ? sprintf('unprocessed `boost:conv` fence at %s', $where)
                : sprintf('conventions token%s left raw at %s', $hit->path !== null ? sprintf(' "%s"', $hit->path) : '', $where));
        }

        return $out;
    }

    /**
     * Remove boost's OWN rendered `## Project Conventions` block (heading + the
     * following ```yaml fence) from content, so the drop-gate's existing-content
     * scan doesn't treat boost's own prior render as a dependency (codex round-3 —
     * that would make the block undroppable). Operator prose pointers and residual
     * refs/tokens elsewhere survive.
     */
    private function withoutRenderedConventionsBlock(string $content): string
    {
        return (string) preg_replace('/##\s+Project Conventions\s*\R+```yaml\R.*?\R```[ \t]*\R?/su', '', $content);
    }

    /**
     * The portion of an existing guidance file that will SURVIVE this sync — the
     * only content the drop gate should scan for a conventions dependency (codex
     * round-5). Keys on OWNERSHIP, not just marker presence:
     *  - boost does NOT own the file → preserved wholesale → scan all of it (minus
     *    boost's own rendered block);
     *  - boost OWNS it (regenerated wholesale): a markerless owned file's prior
     *    body does NOT survive → scan nothing; a legacy marker-bearing owned file
     *    keeps only its OUT-OF-MARKER residual → scan that.
     */
    private function survivingGuidanceForGate(string $content, bool $boostOwns): string
    {
        if (! $boostOwns) {
            return $this->withoutRenderedConventionsBlock($content);
        }

        if (! str_contains($content, '<!-- boost-core:')) {
            return '';
        }

        $residual = (string) preg_replace('/<!-- boost-core:[a-z]+:start -->.*?<!-- boost-core:[a-z]+:end -->/su', '', $content);

        return $this->withoutRenderedConventionsBlock($residual);
    }
}
