<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Manages the `## Project Conventions` block inside `CLAUDE.md`.
 *
 * Orchestrates ManagedRegion (byte-level marker writer) plus the H2 +
 * explainer comment composition. H2 + explainer live OUTSIDE the markers so
 * operators can edit them without invalidating the managed region.
 *
 * Operator content INSIDE the markers is the source of truth — boost-core
 * never rewrites values, only reports diagnostics. See spec §3.3.
 */
final readonly class ConventionsBlockEmitter
{
    public const H2_HEADING = '## Project Conventions';

    public const START_MARKER = '<!-- boost-core:conventions:start -->';

    public const END_MARKER = '<!-- boost-core:conventions:end -->';

    public const EXPLAINER = '<!-- Managed by boost-core. Edit the YAML between the markers; do not remove or move the markers. -->';

    private ManagedRegion $region;

    private VersionMatcher $versionMatcher;

    public function __construct(?VersionMatcher $versionMatcher = null)
    {
        $this->region = new ManagedRegion(
            start: self::START_MARKER,
            end: self::END_MARKER,
        );
        $this->versionMatcher = $versionMatcher ?? new VersionMatcher();
    }

    /**
     * Extract the raw YAML body between the markers, or null if region missing.
     * Strips the ```yaml ... ``` markdown code fence that scaffold renders
     * around the YAML body so operators get nice markdown rendering in
     * CLAUDE.md viewers but the body is still pure YAML to the parser.
     */
    public function extract(?string $claudeMd): ?string
    {
        $body = $this->region->extract($claudeMd);
        if ($body === null) {
            return null;
        }

        return $this->stripCodeFence($body);
    }

    private function stripCodeFence(string $body): string
    {
        $trimmed = trim($body, "\n");
        if (preg_match('/\A```(?:yaml|yml)?\n(.*)\n```\z/s', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $body;
    }

    /**
     * Parse the YAML body into a host-values array. Returns:
     * - {'values': array, 'diagnostics': []} on success
     * - {'values': null, 'diagnostics': [error]} on parse failure
     * - {'values': null, 'diagnostics': []} when region missing
     *
     * @return array{values: array<mixed, mixed>|null, diagnostics: list<Diagnostic>}
     */
    public function parse(?string $claudeMd): array
    {
        $body = $this->extract($claudeMd);
        if ($body === null) {
            return ['values' => null, 'diagnostics' => []];
        }

        try {
            /** @var array<mixed, mixed>|null $parsed */
            $parsed = Yaml::parse($body);
        } catch (ParseException $parseException) {
            return [
                'values' => null,
                'diagnostics' => [Diagnostic::error(null, 'Project Conventions YAML failed to parse: ' . $parseException->getMessage())],
            ];
        }

        if ($parsed === null) {
            return ['values' => [], 'diagnostics' => []];
        }

        if (! is_array($parsed)) {
            return [
                'values' => null,
                'diagnostics' => [Diagnostic::error(null, 'Project Conventions YAML must decode to a mapping at the root.')],
            ];
        }

        return ['values' => $parsed, 'diagnostics' => []];
    }

    /**
     * Decide what to do on `boost sync` for this CLAUDE.md state.
     *
     * @param  list<VendorSchemaSource>  $sources
     * @return array{contents: string|null, diagnostics: list<Diagnostic>}
     */
    public function syncBlock(?string $claudeMd, array $sources): array
    {
        if ($claudeMd === null) {
            return ['contents' => null, 'diagnostics' => []];
        }

        if ($sources === []) {
            return ['contents' => null, 'diagnostics' => []];
        }

        if (str_contains($claudeMd, self::START_MARKER)) {
            return ['contents' => null, 'diagnostics' => []];
        }

        $h2Position = $this->findH2Position($claudeMd);

        if ($h2Position === null) {
            $appended = $this->buildAppendedSection($sources);
            $separator = str_ends_with($claudeMd, "\n") ? "\n" : "\n\n";

            return [
                'contents' => $claudeMd . $separator . $appended,
                'diagnostics' => [],
            ];
        }

        $h2BodyRange = $this->h2BodyRange($claudeMd, $h2Position);
        $body = substr($claudeMd, $h2BodyRange[0], $h2BodyRange[1] - $h2BodyRange[0]);

        if ($this->isWhitespaceOrCommentsOnly($body)) {
            $scaffold = $this->buildScaffoldRegion($sources);
            $before = substr($claudeMd, 0, $h2BodyRange[0]);
            $after = substr($claudeMd, $h2BodyRange[1]);
            $separator = '';
            if (! str_ends_with($before, "\n")) {
                // H2 at EOF with no trailing newline — close the heading line
                // and add a blank line before the scaffold.
                $separator = "\n\n";
            } elseif (! str_ends_with($before, "\n\n") && $body === '') {
                $separator = "\n";
            }

            return [
                'contents' => $before . $separator . $scaffold . $after,
                'diagnostics' => [],
            ];
        }

        return [
            'contents' => null,
            'diagnostics' => [
                Diagnostic::warning(
                    null,
                    'Project Conventions section exists in CLAUDE.md but contains pre-existing content and no boost-core markers. Either move the content into a marker-bounded YAML block manually, or rename the section.',
                ),
            ],
        ];
    }

    /**
     * Computes the seed schema-version for new scaffolds.
     *
     * seed = max(VersionMatcher::minRequired(vendor.schemaRequired)) ?? 1
     *
     * @param  list<VendorSchemaSource>  $sources
     */
    public function scaffoldSeed(array $sources): int
    {
        $max = null;
        foreach ($sources as $source) {
            $min = $this->versionMatcher->minRequired($source->schemaRequired());
            if ($min === null) {
                continue;
            }

            if ($max === null || $min > $max) {
                $max = $min;
            }
        }

        return $max ?? 1;
    }

    private function findH2Position(string $claudeMd): ?int
    {
        $offset = 0;
        while (($pos = strpos($claudeMd, self::H2_HEADING, $offset)) !== false) {
            $atLineStart = $pos === 0 || $claudeMd[$pos - 1] === "\n";
            $endIdx = $pos + strlen(self::H2_HEADING);
            $atLineEnd = $endIdx === strlen($claudeMd) || $claudeMd[$endIdx] === "\n" || ctype_space($claudeMd[$endIdx]);
            if ($atLineStart && $atLineEnd) {
                return $pos;
            }

            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * Returns [bodyStart, bodyEnd) — byte positions of the H2's body (after the
     * heading line, until the next H2/H1 or EOF).
     *
     * @return array{0: int, 1: int}
     */
    private function h2BodyRange(string $claudeMd, int $h2Position): array
    {
        $headingLineEnd = strpos($claudeMd, "\n", $h2Position);
        $bodyStart = $headingLineEnd === false ? strlen($claudeMd) : $headingLineEnd + 1;

        $bodyEnd = strlen($claudeMd);
        $offset = $bodyStart;
        while (($next = strpos($claudeMd, "\n## ", $offset)) !== false) {
            $bodyEnd = $next + 1;
            break;
        }

        if (($next = strpos($claudeMd, "\n# ", $bodyStart)) !== false && $next + 1 < $bodyEnd) {
            $bodyEnd = $next + 1;
        }

        return [$bodyStart, $bodyEnd];
    }

    private function isWhitespaceOrCommentsOnly(string $body): bool
    {
        $stripped = preg_replace('/<!--.*?-->/s', '', $body);
        if ($stripped === null) {
            return false;
        }

        return trim($stripped) === '';
    }

    /**
     * Render operator-supplied conventions values (from `$config->conventions`)
     * as a marker-bounded YAML region. Returns the full CLAUDE.md content with
     * the rendered region merged into place via ManagedRegion (preserving
     * everything outside the markers).
     *
     * Used by `SyncEngine::syncConventions()` — the source of truth
     * is `BoostConfig::$conventions`, not the marker body in CLAUDE.md.
     *
     * @param  list<VendorSchemaSource>  $sources
     * @param  array<string, mixed>  $conventions
     * @return array{contents: string|null, diagnostics: list<Diagnostic>}  null contents = no change relative to existing
     */
    public function renderFromValues(?string $claudeMd, array $sources, array $conventions): array
    {
        if ($sources === []) {
            return ['contents' => null, 'diagnostics' => []];
        }

        $seed = $this->scaffoldSeed($sources);
        $renderedRegion = $this->buildRenderedRegion($conventions, $seed);

        if ($claudeMd === null) {
            if ($conventions === []) {
                // No CLAUDE.md, no operator-declared conventions — nothing
                // to bootstrap. Schema scaffold alone is not worth creating
                // the file for; the user-facing surface stays empty until
                // they declare values OR a guideline write creates the file.
                return ['contents' => null, 'diagnostics' => []];
            }

            // No CLAUDE.md but operator declared values in boost.php via
            // `->withConventions([...])`. Bootstrap CLAUDE.md with H2 +
            // rendered region so the declared values are actually visible
            // to agents — without this branch, fresh repos with
            // conventions-only-no-guidelines would have validated
            // conventions in boost.php that never reach the file agents
            // read.
            return [
                'contents' => self::H2_HEADING . "\n\n" . $renderedRegion,
                'diagnostics' => [],
            ];
        }

        if (! str_contains($claudeMd, self::START_MARKER)) {
            // No marker region yet. Need to scaffold the H2 + explainer +
            // rendered region. Reuse the syncBlock scaffold path with one
            // tweak: substitute the rendered body for the scaffold body.
            $h2Position = $this->findH2Position($claudeMd);

            if ($h2Position === null) {
                $appended = self::H2_HEADING . "\n\n" . $renderedRegion;
                $separator = str_ends_with($claudeMd, "\n") ? "\n" : "\n\n";

                return [
                    'contents' => $claudeMd . $separator . $appended,
                    'diagnostics' => [],
                ];
            }

            $h2BodyRange = $this->h2BodyRange($claudeMd, $h2Position);
            $body = substr($claudeMd, $h2BodyRange[0], $h2BodyRange[1] - $h2BodyRange[0]);

            if ($this->isWhitespaceOrCommentsOnly($body)) {
                $before = substr($claudeMd, 0, $h2BodyRange[0]);
                $after = substr($claudeMd, $h2BodyRange[1]);
                $separator = '';
                if (! str_ends_with($before, "\n")) {
                    $separator = "\n\n";
                } elseif (! str_ends_with($before, "\n\n") && $body === '') {
                    $separator = "\n";
                }

                return [
                    'contents' => $before . $separator . $renderedRegion . $after,
                    'diagnostics' => [],
                ];
            }

            // H2 exists, body has non-whitespace content, no markers. Same
            // safety contract as syncBlock — warn, don't auto-scaffold.
            return [
                'contents' => null,
                'diagnostics' => [
                    Diagnostic::warning(
                        null,
                        'Project Conventions section exists in CLAUDE.md but contains pre-existing content and no boost-core markers. Either move the content into a marker-bounded YAML block manually, or rename the section.',
                    ),
                ],
            ];
        }

        // Marker region present. Replace its body via ManagedRegion semantics.
        $body = self::EXPLAINER . "\n" . self::START_MARKER . "\n" . $this->buildRenderedBody($conventions, $seed) . "\n" . self::END_MARKER;
        $contents = $this->replaceRegionBlock($claudeMd, $body);

        return [
            'contents' => $contents === $claudeMd ? null : $contents,
            'diagnostics' => [],
        ];
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     */
    private function buildAppendedSection(array $sources): string
    {
        return self::H2_HEADING . "\n\n" . $this->buildScaffoldRegion($sources);
    }

    /**
     * @param  array<string, mixed>  $conventions
     */
    private function buildRenderedRegion(array $conventions, int $seed): string
    {
        $lines = [
            self::EXPLAINER,
            self::START_MARKER,
            $this->buildRenderedBody($conventions, $seed),
            self::END_MARKER,
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render the operator-supplied conventions values as a YAML body. Wraps
     * in `\`\`\`yaml ... \`\`\`` markdown fences so the region reads as
     * properly-rendered markdown in editors. `extract()` strips the fence
     * automatically when reading back.
     *
     * @param  array<string, mixed>  $conventions
     */
    private function buildRenderedBody(array $conventions, int $seed): string
    {
        $payload = ['schema-version' => $seed, ...$conventions];
        $yaml = Yaml::dump($payload, inline: 99, indent: 2);

        return "```yaml\n" . rtrim($yaml, "\n") . "\n```";
    }

    /**
     * Replace the body between markers in CLAUDE.md with a freshly-rendered
     * region. The markers themselves get rewritten alongside the body — this
     * keeps the explainer comment + start/end markers in lock-step with
     * the rest of the spec's wording.
     */
    private function replaceRegionBlock(string $claudeMd, string $newBlock): string
    {
        $startPos = strpos($claudeMd, self::START_MARKER);
        if ($startPos === false) {
            return $claudeMd;
        }

        // Walk back to include the explainer comment line ABOVE the start
        // marker (if present) so re-renders don't accumulate stale explainers.
        $explainerStart = $startPos;
        $beforeMarker = substr($claudeMd, 0, $startPos);
        if (preg_match('/(?:^|\n)' . preg_quote(self::EXPLAINER, '/') . '\n?$/', $beforeMarker, $m, PREG_OFFSET_CAPTURE) === 1) {
            $explainerStart = $m[0][1] + (str_starts_with($m[0][0], "\n") ? 1 : 0);
        }

        $endPos = strpos($claudeMd, self::END_MARKER, $startPos);
        if ($endPos === false) {
            return $claudeMd;
        }

        $endLineEnd = strpos($claudeMd, "\n", $endPos);
        $blockEnd = $endLineEnd === false ? strlen($claudeMd) : $endLineEnd + 1;

        $before = substr($claudeMd, 0, $explainerStart);
        $after = substr($claudeMd, $blockEnd);

        return $before . $newBlock . "\n" . $after;
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     */
    private function buildScaffoldRegion(array $sources): string
    {
        $seed = $this->scaffoldSeed($sources);
        $yamlBody = $this->buildScaffoldBody($sources, $seed);

        $lines = [
            self::EXPLAINER,
            self::START_MARKER,
            '```yaml',
            $yamlBody,
            '```',
            self::END_MARKER,
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     */
    private function buildScaffoldBody(array $sources, int $seed): string
    {
        $lines = [
            '# Generated region — body editable. Add slot values per allowlisted vendors\' schemas.',
            "schema-version: {$seed}",
        ];

        foreach ($sources as $source) {
            $required = $this->collectRequiredSlots($source);
            if ($required === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = "# {$source->vendorName} — required slots:";
            foreach ($required as $slotPath) {
                $lines[] = "# {$slotPath}: <fill in>";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function collectRequiredSlots(VendorSchemaSource $source): array
    {
        $schema = $source->schema;
        $required = $schema['required'] ?? [];
        if (! is_array($required)) {
            return [];
        }

        /** @var list<string> $out */
        $out = [];
        foreach ($required as $entry) {
            if (is_string($entry) && $entry !== 'schema-version') {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
