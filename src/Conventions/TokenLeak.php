<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * A classified conventions-token leak in an emitted file. Pairs a {@see LeakHit} location with a
 * resolved, human-actionable `cause` — produced by
 * {@see ConventionTokenLeakScanner::scan()}.
 */
final readonly class TokenLeak
{
    public function __construct(
        public string $relativePath,
        public int $line,
        public string $kind,
        public ?string $path,
        public ?string $mode,
        public string $cause,
    ) {}

    /**
     * `<relativePath>:<line>` location string for reporting.
     */
    public function location(): string
    {
        return $this->relativePath . ':' . $this->line;
    }

    /**
     * @return array{file: string, line: int, kind: string, path: ?string, mode: ?string, cause: string}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->relativePath,
            'line' => $this->line,
            'kind' => $this->kind,
            'path' => $this->path,
            'mode' => $this->mode,
            'cause' => $this->cause,
        ];
    }
}
