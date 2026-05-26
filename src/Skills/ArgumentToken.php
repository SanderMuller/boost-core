<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * One token in a parsed `.ai/commands/<name>.md` body.
 *
 * Source bodies use a small canonical placeholder language:
 *  - `$ARGUMENTS`  — the unsplit string of everything after the command name
 *  - `$N`          — one-indexed positional argument (`$1` = first arg)
 *  - `$name`       — named argument (declared in frontmatter `arguments:` list)
 *  - `\$ARGUMENTS` / `\$1` / `\$name` — literal escapes
 *
 * The parser emits a flat list of these tokens. The per-agent transpiler
 * walks the list and emits the agent-specific shape (or warns when the
 * target can't represent a placeholder at all).
 *
 * Sealed-by-convention: only the four kinds below are produced by
 * {@see ArgumentParser}. Pattern-match exhaustively on `$token->kind`.
 */
final readonly class ArgumentToken
{
    public const string KIND_LITERAL = 'literal';

    public const string KIND_ARGUMENTS = 'arguments';

    public const string KIND_POSITIONAL = 'positional';

    public const string KIND_NAMED = 'named';

    /**
     * @param  string  $kind  one of the KIND_* constants
     * @param  string  $value  literal text for KIND_LITERAL, the named-arg name for KIND_NAMED, "" for KIND_ARGUMENTS
     * @param  int|null  $position  one-indexed position for KIND_POSITIONAL, null otherwise
     */
    public function __construct(
        public string $kind,
        public string $value,
        public ?int $position = null,
    ) {}

    public static function literal(string $text): self
    {
        return new self(self::KIND_LITERAL, $text);
    }

    public static function arguments(): self
    {
        return new self(self::KIND_ARGUMENTS, '');
    }

    public static function positional(int $oneIndexed): self
    {
        return new self(self::KIND_POSITIONAL, '', $oneIndexed);
    }

    public static function named(string $name): self
    {
        return new self(self::KIND_NAMED, $name);
    }
}
