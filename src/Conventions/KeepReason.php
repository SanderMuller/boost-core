<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * One reason the `## Project Conventions` block was KEPT rather than dropped —
 * the provenance behind the drop gate in {@see ConventionsPass}. Names the
 * tripping artifact (a skill, a guidance file, or the no-migration-yet case) and
 * the concrete cause, so an operator who expected the block to drop can find the
 * single thing still holding it open instead of black-box probing (the #87 gap).
 *
 * Advisory only — the gate decision is computed identically with or without this
 * record; collecting it never changes whether the block is written.
 */
final readonly class KeepReason
{
    public function __construct(
        /** e.g. `skill: write-spec`, `guidance: CLAUDE.md`, or `(no migration yet)`. */
        public string $artifact,
        /** e.g. ``legacy slot reference `$.spec.filename_pattern` ``. */
        public string $cause,
    ) {}

    public function describe(): string
    {
        return sprintf('%s — %s', $this->artifact, $this->cause);
    }

    /**
     * @return array{artifact: string, cause: string}
     */
    public function toArray(): array
    {
        return ['artifact' => $this->artifact, 'cause' => $this->cause];
    }
}
