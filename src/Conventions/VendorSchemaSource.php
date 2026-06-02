<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * One vendor's loaded `resources/boost/conventions-schema.json` source.
 *
 * Discovered by SchemaDiscovery, consumed by ConventionsSchema::compose().
 *
 * @internal
 */
final readonly class VendorSchemaSource
{
    /**
     * @param  array<mixed, mixed>  $schema  decoded JSON
     */
    public function __construct(
        public string $vendorName,
        public string $schemaPath,
        public array $schema,
    ) {}

    /**
     * The semver range this vendor's schema applies against; null = wildcard.
     */
    public function schemaRequired(): ?string
    {
        $metadata = $this->schema['metadata'] ?? null;
        if (! is_array($metadata)) {
            return null;
        }

        $required = $metadata['schema-required'] ?? null;

        return is_string($required) ? $required : null;
    }
}
