<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Generic marker-bounded round-trip writer for plain-text files.
 *
 * Maintains a managed region between two marker lines inside an arbitrary
 * host file (Markdown, JSON, ini-style, .gitignore). Lines outside the
 * markers are preserved verbatim.
 *
 * Behavior:
 * - region missing + body empty → no change
 * - region missing + body non-empty → append block
 * - region exists + body non-empty → rebuild block in place
 * - region exists + body empty     → strip block
 *
 * Used by Sync\GitignoreManager and Conventions\ConventionsBlockEmitter.
 *
 * @internal
 */
final readonly class ManagedRegion
{
    public function __construct(
        public string $start,
        public string $end,
        public ?string $note = null,
    ) {}

    /**
     * Returns new contents, or null if no change is needed relative to $existing.
     */
    public function render(?string $existing, string $body): ?string
    {
        $existing ??= '';
        $body = $this->normalizeBody($body);

        [$before, $hasBlock, $after] = $this->split($existing);

        if (! $hasBlock && $body === '') {
            return null;
        }

        if (! $hasBlock) {
            return $this->appendBlock($existing, $body);
        }

        if ($body === '') {
            $stripped = $this->stripBlock($before, $after);

            return $stripped === $existing ? null : $stripped;
        }

        $rebuilt = $this->joinAround($before, $this->buildBlock($body), $after);

        return $rebuilt === $existing ? null : $rebuilt;
    }

    /**
     * Strips the managed region. Returns new contents, or null if no change.
     */
    public function strip(?string $existing): ?string
    {
        $existing ??= '';

        [$before, $hasBlock, $after] = $this->split($existing);

        if (! $hasBlock) {
            return null;
        }

        $stripped = $this->stripBlock($before, $after);

        return $stripped === $existing ? null : $stripped;
    }

    /**
     * Extracts the current body between start/end markers.
     * Returns null if region missing. Returned body EXCLUDES the marker lines
     * themselves and any inline $note line after the start marker.
     */
    public function extract(?string $existing): ?string
    {
        if ($existing === null || $existing === '') {
            return null;
        }

        $startPos = strpos($existing, $this->start);
        if ($startPos === false) {
            return null;
        }

        $endPos = strpos($existing, $this->end, $startPos);
        if ($endPos === false) {
            return null;
        }

        $startLineEnd = strpos($existing, "\n", $startPos);
        if ($startLineEnd === false || $startLineEnd >= $endPos) {
            return '';
        }

        $bodyStart = $startLineEnd + 1;

        if ($this->note !== null) {
            $expectedNoteLine = $this->note . "\n";
            if (str_starts_with(substr($existing, $bodyStart), $expectedNoteLine)) {
                $bodyStart += strlen($expectedNoteLine);
            }
        }

        $body = substr($existing, $bodyStart, $endPos - $bodyStart);

        return rtrim($body, "\n");
    }

    /**
     * @return array{0: string, 1: bool, 2: string}
     */
    private function split(string $contents): array
    {
        $startPos = strpos($contents, $this->start);
        if ($startPos === false) {
            return [$contents, false, ''];
        }

        $endPos = strpos($contents, $this->end, $startPos);
        if ($endPos === false) {
            return [$contents, false, ''];
        }

        $endLineEnd = strpos($contents, "\n", $endPos);
        $after = $endLineEnd === false ? '' : substr($contents, $endLineEnd + 1);

        return [substr($contents, 0, $startPos), true, $after];
    }

    private function appendBlock(string $existing, string $body): string
    {
        $separator = $existing === '' ? '' : (str_ends_with($existing, "\n") ? '' : "\n");

        return $existing . $separator . $this->buildBlock($body);
    }

    private function buildBlock(string $body): string
    {
        $lines = [$this->start];
        if ($this->note !== null) {
            $lines[] = $this->note;
        }

        $lines[] = $body;
        $lines[] = $this->end;

        return implode("\n", $lines) . "\n";
    }

    private function joinAround(string $before, string $block, string $after): string
    {
        if ($before !== '' && ! str_ends_with($before, "\n")) {
            $before .= "\n";
        }

        return $before . $block . $after;
    }

    private function stripBlock(string $before, string $after): string
    {
        $before = rtrim($before, "\n");
        if ($before === '' && $after === '') {
            return '';
        }

        if ($after === '') {
            return $before . "\n";
        }

        return ($before === '' ? '' : $before . "\n") . $after;
    }

    private function normalizeBody(string $body): string
    {
        return rtrim($body, "\n");
    }
}
