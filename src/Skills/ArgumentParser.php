<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Parses a `.ai/commands/<name>.md` body into a flat list of
 * {@see ArgumentToken}s — runs of literal text interleaved with the four
 * canonical placeholder kinds (`$ARGUMENTS`, `$N`, `$name`, escaped
 * `\$...`).
 *
 * Canonical syntax:
 *  - `$ARGUMENTS`      — the entire unsplit args string
 *  - `$1`, `$2`, …     — one-indexed positional arg (`$1` = first)
 *  - `$word`           — named arg (`/[a-zA-Z_][a-zA-Z0-9_]*\/`)
 *  - `\$<anything>`    — literal `$<anything>` in the output, no placeholder
 *
 * Boundary rules:
 *  - `$N` and `$name` must be word-boundary terminated (`$1deploy` is
 *    parsed as the literal string `$1deploy`, NOT positional `$1`
 *    followed by `deploy` — placeholders are explicit tokens, not
 *    substring captures).
 *  - Unknown bareword `$foo` IS valid as KIND_NAMED — the transpiler
 *    decides whether `foo` matches a declared frontmatter argument and
 *    how to emit it per-agent.
 */
final readonly class ArgumentParser
{
    /**
     * @return list<ArgumentToken>
     */
    public function parse(string $body): array
    {
        $tokens = [];
        $literalBuffer = '';
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            $char = $body[$i];

            // `\$<anything>` → emit `$<anything>` as literal; skip the `\`.
            if ($char === '\\' && $i + 1 < $length && $body[$i + 1] === '$') {
                $literalBuffer .= '$';
                $i += 2;

                continue;
            }

            if ($char !== '$') {
                $literalBuffer .= $char;
                ++$i;

                continue;
            }

            // We hit `$`. Try the three placeholder shapes in order:
            //   $ARGUMENTS, $<digits>, $<wordchars>
            $tail = substr($body, $i);

            if (preg_match('/^\$ARGUMENTS\b/', $tail, $matches) === 1) {
                if ($literalBuffer !== '') {
                    $tokens[] = ArgumentToken::literal($literalBuffer);
                    $literalBuffer = '';
                }
                $tokens[] = ArgumentToken::arguments();
                $i += strlen($matches[0]);

                continue;
            }

            if (preg_match('/^\$(\d+)\b/', $tail, $matches) === 1) {
                if ($literalBuffer !== '') {
                    $tokens[] = ArgumentToken::literal($literalBuffer);
                    $literalBuffer = '';
                }
                $tokens[] = ArgumentToken::positional((int) $matches[1]);
                $i += strlen($matches[0]);

                continue;
            }

            if (preg_match('/^\$([a-zA-Z_]\w*)\b/', $tail, $matches) === 1) {
                if ($literalBuffer !== '') {
                    $tokens[] = ArgumentToken::literal($literalBuffer);
                    $literalBuffer = '';
                }
                $tokens[] = ArgumentToken::named($matches[1]);
                $i += strlen($matches[0]);

                continue;
            }

            // Bare `$` followed by something we don't recognize as a
            // placeholder — emit the `$` as literal and continue.
            $literalBuffer .= '$';
            ++$i;
        }

        if ($literalBuffer !== '') {
            $tokens[] = ArgumentToken::literal($literalBuffer);
        }

        return $tokens;
    }
}
