<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Result of transpiling a canonical command body for a specific agent.
 * Carries the rendered body and any warnings the per-agent rules
 * produced (e.g. "Cursor has no placeholder support; body emitted
 * verbatim").
 *
 * Warnings are lenient — they surface through `SyncResult::errors`
 * (operator-visible) but never abort the sync. The `boost where`
 * pipeline already establishes this pattern: render what we can, name
 * the gap honestly.
 */
final readonly class CommandTranspileResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $content,
        public array $warnings = [],
    ) {}
}
