<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Loads + composes per-vendor JSONSchemas. Validates host YAML against the
 * composed schema via `opis/json-schema` (draft 2020-12).
 *
 * Composition rules (spec §5):
 * - same-typed slot collision → first-allowlisted wins silently
 * - different-typed slot collision → throw SlotTypeMismatchException
 * - required-union → stricter contract wins
 *
 * Compose stripping rules (spec §3.2):
 * - strip root `properties.schema-version`
 * - strip the literal "schema-version" entry from root `required`
 * - inject synthetic `properties.schema-version: {type: integer, minimum: 1}`
 *
 * compose() is version-agnostic; version filtering lives in SyncEngine (per
 * spec §3.9).
 *
 * @internal
 */
final readonly class ConventionsSchema
{
    /**
     * @param  list<VendorSchemaSource>  $sources  in allowlist order
     */
    public function __construct(public array $sources) {}

    /**
     * Schema-version handshake enforcement (spec §3.9). Partition sources by
     * whether the effective host schema-version satisfies each vendor's declared
     * `metadata.schema-required` range:
     *  - in-range sources are returned in `applied` (composed + validated normally);
     *  - an out-of-range source is DROPPED (its slots never compose, so its tokens
     *    resolve to render errors — its conventions are NOT applied) and gets an
     *    ERROR-level diagnostic naming the vendor + range + host version.
     *
     * A `null`/`*`/empty range always satisfies, so a vendor without a
     * `schema-required` is never enforced out (the no-false-trip common case).
     *
     * The single enforcement authority, shared by {@see ConventionsPass::build()}
     * (the sync path) and `boost validate` so the two classify identically.
     *
     * @param  list<VendorSchemaSource>  $sources
     * @return array{applied: list<VendorSchemaSource>, diagnostics: list<Diagnostic>}
     */
    public static function enforceSchemaVersion(array $sources, int $hostVersion, ?VersionMatcher $matcher = null): array
    {
        $matcher ??= new VersionMatcher();

        /** @var list<VendorSchemaSource> $applied */
        $applied = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        foreach ($sources as $source) {
            $required = $source->schemaRequired();
            if ($matcher->satisfies($hostVersion, $required)) {
                $applied[] = $source;

                continue;
            }

            $diagnostics[] = Diagnostic::error(
                null,
                sprintf(
                    'conventions schema-version mismatch: the host Project Conventions block is at schema-version %d, but vendor `%s` requires "%s" — this vendor\'s conventions are NOT applied (its tokens will not resolve). Align the host schema-version or the vendor allowlist.',
                    $hostVersion,
                    $source->vendorName,
                    (string) $required,
                ),
                $source->vendorName,
            );
        }

        return ['applied' => $applied, 'diagnostics' => $diagnostics];
    }

    /**
     * @return array{'$schema': string, type: string, properties: array<string, array<mixed, mixed>>, required: list<string>, additionalProperties: bool}
     *
     * @throws SlotTypeMismatchException
     */
    public function compose(): array
    {
        /** @var array<string, array<mixed, mixed>> $properties */
        $properties = [];
        /** @var array<string, string> $propertyOwner  property-path → vendor that introduced it */
        $propertyOwner = [];
        /** @var array<string, bool> $required */
        $required = [];

        foreach ($this->sources as $source) {
            $schema = $this->stripRootSchemaVersion($source->schema);

            $vendorProps = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($vendorProps as $propName => $propSchema) {
                if (! is_string($propName)) {
                    continue;
                }

                if (! is_array($propSchema)) {
                    continue;
                }

                if (! isset($properties[$propName])) {
                    $properties[$propName] = $propSchema;
                    $propertyOwner[$propName] = $source->vendorName;

                    continue;
                }

                $existing = $properties[$propName];
                $existingType = is_string($existing['type'] ?? null) ? $existing['type'] : null;
                $incomingType = is_string($propSchema['type'] ?? null) ? $propSchema['type'] : null;

                if ($existingType !== null && $incomingType !== null && $existingType !== $incomingType) {
                    throw new SlotTypeMismatchException(
                        message: sprintf(
                            'Slot "%s" type mismatch: vendor "%s" declares %s, vendor "%s" declares %s.',
                            $propName,
                            $propertyOwner[$propName],
                            $existingType,
                            $source->vendorName,
                            $incomingType,
                        ),
                        slotPath: $propName,
                        firstVendor: $propertyOwner[$propName],
                        secondVendor: $source->vendorName,
                    );
                }
            }

            $vendorRequired = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($vendorRequired as $entry) {
                if (is_string($entry)) {
                    $required[$entry] = true;
                }
            }
        }

        $properties['schema-version'] = ['type' => 'integer', 'minimum' => 1];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($required),
            'additionalProperties' => true,
        ];
    }

    /**
     * Validates the host values against the composed schema.
     *
     * @param  array<mixed, mixed>  $hostValues
     * @return list<Diagnostic>
     */
    public function validate(array $hostValues): array
    {
        try {
            $composed = $this->compose();
        } catch (SlotTypeMismatchException $slotTypeMismatchException) {
            return [Diagnostic::error($slotTypeMismatchException->slotPath, $slotTypeMismatchException->getMessage())];
        }

        $validator = new Validator();
        $validator->setMaxErrors(50);

        $schemaJson = json_encode($composed, JSON_THROW_ON_ERROR);

        // Empty PHP array (the default for `$config->conventions` when no
        // `withConventions([...])` was declared) serializes to a JSON array
        // `[]`, but the conventions schema declares `type: object`. opis/json-
        // schema then rejects with "The data (array) must match the type:
        // object". Cast to stdClass so the empty case serializes to `{}` and
        // validates cleanly. Non-empty associative arrays already serialize
        // to objects via Helper::toJSON, so this only affects the empty case.
        $data = $hostValues === [] ? new stdClass() : Helper::toJSON($hostValues);

        $result = $validator->validate($data, $schemaJson);

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = $this->unknownSlotDiagnostics($composed, $hostValues);

        if ($result->isValid()) {
            return $diagnostics;
        }

        $error = $result->error();
        if (! $error instanceof ValidationError) {
            return $diagnostics;
        }

        return [...$diagnostics, ...$this->formatErrors($error)];
    }

    /**
     * Walks host keys against composed properties; emits warning diagnostics
     * for keys not declared by any allowlisted vendor's schema. opis treats
     * undeclared keys as accepted via `additionalProperties: true`; this
     * separate diff surfaces them as warnings per spec §14.
     *
     * @param  array{'$schema': string, type: string, properties: array<string, array<mixed, mixed>>, required: list<string>, additionalProperties: bool}  $composed
     * @param  array<mixed, mixed>  $hostValues
     * @return list<Diagnostic>
     */
    private function unknownSlotDiagnostics(array $composed, array $hostValues): array
    {
        $declared = $composed['properties'];

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        foreach (array_keys($hostValues) as $key) {
            if (! is_string($key)) {
                continue;
            }

            if (! isset($declared[$key])) {
                $diagnostics[] = Diagnostic::warning($key, 'unknown slot (not declared by any allowlisted vendor)');
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     * @return array<mixed, mixed>
     */
    private function stripRootSchemaVersion(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            unset($schema['properties']['schema-version']);
        }

        if (isset($schema['required']) && is_array($schema['required'])) {
            $schema['required'] = array_values(array_filter(
                $schema['required'],
                static fn (mixed $entry): bool => $entry !== 'schema-version',
            ));
        }

        return $schema;
    }

    /**
     * @return list<Diagnostic>
     */
    private function formatErrors(ValidationError $error): array
    {
        $formatter = new ErrorFormatter();
        /** @var array<string, list<string>> $formatted */
        $formatted = $formatter->format($error, true);

        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        foreach ($formatted as $slot => $messages) {
            $slotPath = $slot === '' ? null : $this->jsonPointerToDotPath($slot);
            foreach ($messages as $message) {
                $diagnostics[] = Diagnostic::error($slotPath, $message);
            }
        }

        return $diagnostics;
    }

    private function jsonPointerToDotPath(string $pointer): string
    {
        $trimmed = ltrim($pointer, '/');
        if ($trimmed === '') {
            return '';
        }

        return str_replace('/', '.', $trimmed);
    }
}
