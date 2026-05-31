<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * The on-request convention audit surface (0.15.0, spec D5). With inlining the
 * always-loaded `## Project Conventions` block is dropped once a project is
 * fully migrated, so this replaces "read the block in CLAUDE.md" — it reports
 * the EFFECTIVE resolved value of every slot the composed schema defines, with
 * explicit PROVENANCE (declared / schema-default / missing), for
 * `boost where --conventions` (human table + `--json`).
 *
 * @phpstan-type AuditRow array{path: string, provenance: string, value: mixed}
 */
final readonly class ConventionsAudit
{
    public const DECLARED = 'declared';

    public const SCHEMA_DEFAULT = 'schema-default';

    public const MISSING = 'missing';

    /**
     * @param  array<string, mixed>  $conventions     declared values from boost.php
     * @param  array<string, mixed>  $composedSchema  ConventionsSchema::compose() output
     * @return list<array{path: string, provenance: string, value: mixed}>  one row per leaf slot, sorted by path
     */
    public function audit(array $conventions, array $composedSchema): array
    {
        $rows = [];
        foreach ($this->leafPaths($composedSchema, '') as $path) {
            $declared = $this->lookup($conventions, $path);
            if ($declared['found']) {
                $rows[] = ['path' => $path, 'provenance' => self::DECLARED, 'value' => $declared['value']];

                continue;
            }

            $leaf = $this->leafSchema($composedSchema, $path);
            if ($leaf !== null && array_key_exists('default', $leaf)) {
                $rows[] = ['path' => $path, 'provenance' => self::SCHEMA_DEFAULT, 'value' => $leaf['default']];

                continue;
            }

            $rows[] = ['path' => $path, 'provenance' => self::MISSING, 'value' => null];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $rows;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     * @return list<string>
     */
    private function leafPaths(array $schema, string $prefix): array
    {
        $props = $schema['properties'] ?? null;
        if (! is_array($props)) {
            return [];
        }

        $out = [];
        foreach ($props as $name => $sub) {
            if (! is_string($name)) {
                continue;
            }

            if ($name === 'schema-version') {
                continue;
            }

            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            if (is_array($sub) && isset($sub['properties']) && is_array($sub['properties'])) {
                $out = [...$out, ...$this->leafPaths($sub, $path)];

                continue;
            }

            $out[] = $path;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<mixed, mixed>|null
     */
    private function leafSchema(array $schema, string $path): ?array
    {
        $node = $schema;
        foreach (explode('.', $path) as $segment) {
            $props = $node['properties'] ?? null;
            if (! is_array($props) || ! isset($props[$segment]) || ! is_array($props[$segment])) {
                return null;
            }

            $node = $props[$segment];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $conventions
     * @return array{found: bool, value: mixed}
     */
    private function lookup(array $conventions, string $path): array
    {
        $node = $conventions;
        $segments = explode('.', $path);
        foreach ($segments as $i => $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return ['found' => false, 'value' => null];
            }

            if ($i === count($segments) - 1) {
                return ['found' => true, 'value' => $node[$segment]];
            }

            $node = $node[$segment];
        }

        return ['found' => false, 'value' => null];
    }
}
