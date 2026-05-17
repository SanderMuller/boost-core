<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final readonly class Guideline
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through.
     * @param  string|null  $sourceVendor  Composer vendor/package name. `null` = host-authored.
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
