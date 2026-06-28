<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use InvalidArgumentException;

/**
 * Structured diagnostic produced by conventions schema discovery / composition
 * / validation. Severity-bearing — carries `error` / `warning` / `info`.
 *
 * Routes through SyncResult::diagnostics, never through the
 * SyncResult::errors fatal channel. Default rendering is per command
 * (✓/✗/⚠/ℹ). JSON envelope carries `level` explicitly.
 *
 * @api Stable as of 1.0 — an item of {@see SyncResult::$diagnostics}.
 * The four read properties (`level`, `slot`, `message`, `vendor`) are frozen;
 * `level` is one of `error`/`warning`/`info`. The `LEVEL_*` constants and the
 * `error()`/`warning()`/`info()` factories are convenience, not part of the
 * frozen read surface.
 */
final readonly class Diagnostic
{
    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    private const LEVELS = [self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_INFO];

    public function __construct(
        public string $level,
        public ?string $slot,
        public string $message,
        public ?string $vendor = null,
    ) {
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Diagnostic level must be one of %s; got "%s".',
                implode('/', self::LEVELS),
                $level,
            ));
        }
    }

    public static function error(?string $slot, string $message, ?string $vendor = null): self
    {
        return new self(self::LEVEL_ERROR, $slot, $message, $vendor);
    }

    public static function warning(?string $slot, string $message, ?string $vendor = null): self
    {
        return new self(self::LEVEL_WARNING, $slot, $message, $vendor);
    }

    public static function info(?string $slot, string $message, ?string $vendor = null): self
    {
        return new self(self::LEVEL_INFO, $slot, $message, $vendor);
    }

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    /**
     * @return array{level: string, slot: string|null, message: string, vendor: string|null}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'slot' => $this->slot,
            'message' => $this->message,
            'vendor' => $this->vendor,
        ];
    }
}
