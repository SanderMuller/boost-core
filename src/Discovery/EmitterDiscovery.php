<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use JsonException;
use SanderMuller\BoostCore\Contracts\FileEmitter;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Throwable;

/**
 * Discovers FileEmitter classes registered by allowlisted vendor packages
 * via `extra.boost.emitters` in their composer.json.
 *
 * Discovery rules (per FileEmitter contract):
 * - Only allowlisted vendors are scanned. Non-allowlisted emitters never
 *   instantiate, even if declared.
 * - Class must autoload. Missing classes are silently skipped (likely a
 *   stale composer.json reference).
 * - Class must implement FileEmitter. Mismatches are silently skipped.
 * - Constructor must be parameterless. Throwing constructors are skipped.
 */
final readonly class EmitterDiscovery
{
    public function __construct(
        private InstalledPackages $packages,
    ) {}

    /**
     * @param  list<string>  $allowedVendors  Composer package names from the host allowlist.
     * @return list<DiscoveredEmitter>
     */
    public function discover(array $allowedVendors): array
    {
        $found = [];

        foreach ($allowedVendors as $vendorName) {
            if (! $this->packages->has($vendorName)) {
                continue;
            }

            $composerJson = $this->packages->path($vendorName) . '/composer.json';
            if (! is_file($composerJson)) {
                continue;
            }

            $config = $this->readComposerJson($composerJson);
            if ($config === null) {
                continue;
            }

            foreach ($this->extractEmitterFqcns($config) as $fqcn) {
                $instance = $this->instantiate($fqcn);
                if (! $instance instanceof FileEmitter) {
                    continue;
                }

                $found[] = new DiscoveredEmitter(
                    emitter: $instance,
                    vendor: $vendorName,
                    fqcn: $fqcn,
                );
            }
        }

        return $found;
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function readComposerJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed, mixed>  $config
     * @return list<string>
     */
    private function extractEmitterFqcns(array $config): array
    {
        $extra = $config['extra'] ?? null;
        if (! is_array($extra)) {
            return [];
        }

        $boost = $extra['boost'] ?? null;
        if (! is_array($boost)) {
            return [];
        }

        $emitters = $boost['emitters'] ?? null;
        if (! is_array($emitters)) {
            return [];
        }

        $fqcns = [];
        foreach ($emitters as $entry) {
            if (is_string($entry) && $entry !== '') {
                $fqcns[] = $entry;
            }
        }

        return $fqcns;
    }

    private function instantiate(string $fqcn): ?FileEmitter
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        try {
            $instance = new $fqcn();
        } catch (Throwable) {
            return null;
        }

        return $instance instanceof FileEmitter ? $instance : null;
    }
}
