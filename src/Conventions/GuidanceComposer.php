<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use Symfony\Component\Yaml\Yaml;

/**
 * Assembles the wholesale, markerless content of an agent-guidance file
 * (CLAUDE.md / AGENTS.md / GEMINI.md) and migrates legacy marker-bounded
 * files.
 *
 * **Why markerless.** Agent-guidance files are boost-emitted category-3
 * paths: operators influence them through
 * `.ai/` sources + `boost.php`, not by hand-editing the emission target. The
 * `boost-core:guidelines:*` / `boost-core:conventions:*` marker pairs that
 * preserved operator content inside the file are therefore a vestige —
 * removing them eliminates the marker verbosity and makes the file wholesale-
 * regenerated each sync.
 *
 * **Composition order** (§Q3): optional Project Conventions section
 * (CLAUDE.md only, when conventions are declared) then the guidelines body.
 *
 * **Migration** (§Q2): a legacy file's existing marker regions are stripped;
 * content remaining outside the markers ("residual") is either silently
 * absorbed (when it duplicates the boost-rendered body — the stale-inline-
 * copy case) or preserved below the wholesale body once, with a warning
 * pointing the operator at `.ai/guidelines/` as the durable home for
 * hand-written content. Never silently lossy.
 */
final readonly class GuidanceComposer
{
    private const GUIDELINES_START = '<!-- boost-core:guidelines:start -->';

    private const GUIDELINES_END = '<!-- boost-core:guidelines:end -->';

    private const GUIDELINES_NOTE = '<!-- Managed by boost-core. Do not remove or move these markers. Content outside is operator-owned. -->';

    public function __construct(
        private ManagedRegion $guidelinesRegion = new ManagedRegion(
            self::GUIDELINES_START,
            self::GUIDELINES_END,
            self::GUIDELINES_NOTE,
        ),
    ) {}

    /**
     * Render the markerless Project Conventions section, or null when there
     * are no declared conventions to render.
     *
     * @param  array<string, mixed>  $conventions
     */
    public function renderConventionsSection(array $conventions, int $schemaSeed): ?string
    {
        if ($conventions === []) {
            return null;
        }

        $payload = ['schema-version' => $schemaSeed, ...$conventions];
        $yaml = rtrim(Yaml::dump($payload, inline: 99, indent: 2), "\n");

        return ConventionsBlockEmitter::H2_HEADING . "\n\n```yaml\n" . $yaml . "\n```";
    }

    /**
     * True when the content still carries boost's legacy guideline / conventions
     * markers — i.e. it is definitively a boost-WRITTEN file (boost emitted
     * those markers). Such a file is always eligible for the one-time markerless
     * migration, even when the resolved guidance set is now empty: converging it
     * (stripping markers, preserving any genuine residual) can never wipe
     * operator-authored content, because boost owns the marked regions.
     */
    public function hasManagedMarkers(string $content): bool
    {
        return str_contains($content, self::GUIDELINES_START)
            || str_contains($content, ConventionsBlockEmitter::START_MARKER);
    }

    /**
     * Assemble the full wholesale content for a guidance file.
     *
     * @param  ?string  $conventionsSection  rendered markerless conventions
     *   section (CLAUDE.md only), or null
     * @param  string  $guidelinesBody  the formatted guideline bodies
     *   (already markerless — joined guideline content)
     */
    public function assemble(?string $conventionsSection, string $guidelinesBody): string
    {
        $parts = [];
        if ($conventionsSection !== null && trim($conventionsSection) !== '') {
            $parts[] = rtrim($conventionsSection, "\n");
        }

        $guidelinesBody = rtrim($guidelinesBody, "\n");
        if ($guidelinesBody !== '') {
            $parts[] = $guidelinesBody;
        }

        if ($parts === []) {
            return '';
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Migrate a legacy file to the wholesale markerless content.
     *
     * @param  ?string  $existing  current on-disk content (null if absent)
     * @param  string  $assembled  the wholesale content to write
     * Two cases, gated on whether the file still carries boost MARKERS:
     *
     *  - **Markers present (one-time migration sync).** Strip the guidelines
     *    region + unwrap the conventions region, drop any block that
     *    duplicates the boost output (a stale inline copy),
     *    and PRESERVE any genuine remaining non-boost content below the
     *    generated body for ONE sync, returning it as `residual` so the caller
     *    can warn (pointing it at `.ai/guidelines/` / convert-conventions).
     *
     *  - **No markers (steady state — file is already boost-owned).** The file
     *    IS boost output; write it wholesale (`content = assembled`). This is
     *    what makes guideline EDITS and DELETIONS converge — the old generated
     *    body is replaced, not carried forward. Any content preserved by the
     *    prior migration sync is removed now (the operator was warned to move
     *    it to `.ai/guidelines/`; git history holds it). Matches how every
     *    other category-3 path is owned (overwrite + recover-from-git).
     *
     * @return array{content: string, residual: ?string, migrated: bool}
     */
    public function migrate(?string $existing, string $assembled): array
    {
        if ($existing === null || trim($existing) === '') {
            return ['content' => $assembled, 'residual' => null, 'migrated' => false];
        }

        if (! $this->hasManagedMarkers($existing)) {
            // Boost-owned markerless file → wholesale overwrite. Convergent;
            // guideline edits/deletions replace the prior body.
            return ['content' => $assembled, 'residual' => null, 'migrated' => false];
        }

        // One-time migration. Reduce to out-of-boost content:
        //  - GUIDELINES region removed entirely (body regenerated);
        //  - CONVENTIONS region UNWRAPPED — marker + explainer comment lines
        //    removed, YAML body kept.
        $residual = trim($this->dropDuplicateBlocks($this->reduceToResidual($existing), $assembled));

        if ($residual === '') {
            return ['content' => $assembled, 'residual' => null, 'migrated' => false];
        }

        // Preserve genuine content below the generated body for this one sync,
        // with a warning. The next (markerless) sync overwrites wholesale.
        $content = rtrim($assembled, "\n") . "\n\n" . $residual . "\n";

        return ['content' => $content, 'residual' => $residual, 'migrated' => true];
    }

    private function reduceToResidual(string $existing): string
    {
        // Normalize CRLF → LF first so marker stripping + the guidelines-region
        // strip are line-ending-agnostic: Windows / CRLF
        // checkouts otherwise keep the conventions markers + explainer in the
        // residual, so the first sync never converges to markerless.
        $reduced = str_replace("\r\n", "\n", $existing);

        // Remove the guidelines region wholesale (body regenerated).
        $reduced = $this->guidelinesRegion->strip($reduced) ?? $reduced;

        // Unwrap the conventions region: strip only the comment lines (each on
        // its own line), keep the YAML body. The `## Project Conventions`
        // heading sits outside the markers and is preserved with the body.
        return str_replace(
            [
                ConventionsBlockEmitter::EXPLAINER . "\n",
                ConventionsBlockEmitter::EXPLAINER,
                ConventionsBlockEmitter::START_MARKER . "\n",
                ConventionsBlockEmitter::START_MARKER,
                ConventionsBlockEmitter::END_MARKER . "\n",
                ConventionsBlockEmitter::END_MARKER,
            ],
            '',
            $reduced,
        );
    }

    /**
     * Remove paragraph blocks from $text whose normalized form also appears in
     * $reference. Blocks are separated by blank lines. Idempotent + collapses
     * the stale-inline-copy of the guidelines without touching genuine
     * operator content (e.g. conventions YAML the reference doesn't contain).
     */
    private function dropDuplicateBlocks(string $text, string $reference): string
    {
        $referenceBlocks = [];
        foreach ($this->splitBlocks($reference) as $block) {
            $referenceBlocks[$this->normalize($block)] = true;
        }

        $kept = [];
        foreach ($this->splitBlocks($text) as $block) {
            $norm = $this->normalize($block);
            if ($norm === '') {
                continue;
            }

            if (isset($referenceBlocks[$norm])) {
                continue;
            }

            $kept[] = trim($block);
        }

        return implode("\n\n", $kept);
    }

    /**
     * @return list<string>
     */
    private function splitBlocks(string $text): array
    {
        $blocks = preg_split('/\n[ \t]*\n/', trim($text));

        return $blocks === false ? [] : array_values(array_filter($blocks, static fn (string $b): bool => trim($b) !== ''));
    }

    private function normalize(string $text): string
    {
        // Collapse all whitespace runs to single spaces + trim, so formatting
        // drift (blank lines, trailing spaces, `---` separators) doesn't defeat
        // duplicate detection. Also drop pure `---` separator blocks.
        $stripped = trim($text, "- \t\n\r");
        $collapsed = preg_replace('/\s+/', ' ', $stripped);

        return trim($collapsed ?? $stripped);
    }
}
