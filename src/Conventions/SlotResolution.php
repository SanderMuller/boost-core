<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Outcome of resolving ONE convention slot token.
 *
 * A token resolves to either:
 *  - success: `$output` is the rendered text to splice in place of the token;
 *  - error: `$error` describes a RENDER-CLASS failure (unknown/typo'd path,
 *    type×mode mismatch, unset-with-no-default-and-no-fallback, multi-line
 *    scalar in inline, …). Per spec D7/§2 an error fails `boost sync --check`,
 *    fails strict validation, and SUPPRESSES the conventions-block drop — never
 *    a silent empty substitution.
 *
 * `$provenance` records WHY a successful resolution produced its value, for the
 * `boost where --conventions` audit surface (D5): `declared` (from boost.php,
 * even if falsy), `schema-default`, or `fallback` (inline token fallback).
 *
 * @internal
 */
final readonly class SlotResolution
{
    public const PROVENANCE_DECLARED = 'declared';

    public const PROVENANCE_SCHEMA_DEFAULT = 'schema-default';

    public const PROVENANCE_FALLBACK = 'fallback';

    private function __construct(
        public bool $ok,
        public string $output,
        public ?string $error,
        public ?string $provenance,
    ) {}

    public static function ok(string $output, string $provenance): self
    {
        return new self(true, $output, null, $provenance);
    }

    public static function error(string $message): self
    {
        return new self(false, '', $message, null);
    }
}
