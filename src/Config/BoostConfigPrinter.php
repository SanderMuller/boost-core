<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use Override;
use PhpParser\Node\Expr\Array_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Pretty-printer for `boost.php` that always expands a non-empty array
 * one item per line with a trailing comma; an empty array stays `[]`.
 *
 * php-parser's `Standard` printer decides array layout from the source
 * line attributes of the items — freshly-built AST nodes (which is what
 * `BoostConfigWriter` constructs when it swaps the agents / allowed-vendors
 * / disabled-emitters lists) carry none, so `Standard` collapses them
 * inline. This subclass forces the readable multi-line shape.
 *
 * Only the arrays `BoostConfigWriter` rebuilds hit this method —
 * format-preserving printing reproduces every unchanged node verbatim,
 * so the fluent chain's own line layout is whatever the source file
 * already had (the starter template ships it multi-line).
 *
 * @internal
 */
final class BoostConfigPrinter extends Standard
{
    #[Override]
    protected function pExpr_Array(Array_ $node): string
    {
        if ($node->items === []) {
            return '[]';
        }

        return '[' . $this->pCommaSeparatedMultiline($node->items, true) . $this->nl . ']';
    }
}
