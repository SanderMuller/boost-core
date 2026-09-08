<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

/**
 * @internal
 */
final readonly class DiscoveredVendor
{
    public function __construct(
        public string $name,
        public string $installPath,
        public ?string $skillsPath,
        public ?string $guidelinesPath,
        // Appended with a default: DiscoveredVendor is constructed positionally
        // in tests and wrapper code, so a required parameter here would break
        // them. Null = the package ships no `resources/boost/subagents/`.
        public ?string $subagentsPath = null,
    ) {}

    public function publishesSkills(): bool
    {
        return $this->skillsPath !== null;
    }

    public function publishesGuidelines(): bool
    {
        return $this->guidelinesPath !== null;
    }

    public function publishesSubagents(): bool
    {
        return $this->subagentsPath !== null;
    }

    public function publishesAnything(): bool
    {
        if ($this->publishesSkills()) {
            return true;
        }

        if ($this->publishesGuidelines()) {
            return true;
        }

        return $this->publishesSubagents();
    }
}
