<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final readonly class Skill
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through. Ecosystem-driven evolution.
     * @param  string|null  $sourceVendor  Composer vendor/package name that published this skill. `null` = host-authored from .ai/skills/.
     * @param  bool  $isDirectoryForm  True when source was `<name>/SKILL.md`, false when source was `<name>.md`. Agents that support nested skill directories (e.g. Claude Code) use this to emit `<name>/SKILL.md` rather than flattening.
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $frontmatter,
        public string $body,
        public string $sourcePath,
        public ?string $sourceVendor,
        public bool $isDirectoryForm = false,
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }
}
