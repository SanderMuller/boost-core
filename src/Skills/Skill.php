<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final readonly class Skill
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through. Ecosystem-driven evolution.
     * @param  string|null  $sourceVendor  Composer vendor/package name that published this skill. `null` = host-authored from .ai/skills/.
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $frontmatter,
        public string $body,
        public string $sourcePath,
        public ?string $sourceVendor,
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }
}
