<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

final readonly class DiscoveredVendor
{
    public function __construct(
        public string $name,
        public string $installPath,
        public ?string $skillsPath,
        public ?string $guidelinesPath,
    ) {}

    public function publishesSkills(): bool
    {
        return $this->skillsPath !== null;
    }

    public function publishesGuidelines(): bool
    {
        return $this->guidelinesPath !== null;
    }

    public function publishesAnything(): bool
    {
        return $this->publishesSkills() || $this->publishesGuidelines();
    }
}
