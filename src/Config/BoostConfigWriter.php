<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use PhpParser\Error;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

/**
 * AST-based writer for boost.php. Rector's pattern: parse → manipulate →
 * pretty-print. Refuses on unexpected shapes to avoid silently destroying
 * user-authored config.
 *
 * Edits supported:
 * - `withAgents([...])` — replace the array of Agent::* constants
 * - `withAllowedVendors([...])` — replace the array of vendor name strings
 * - `withDisabledEmitters([...])` — replace the array of FQCN strings
 *
 * If a method isn't already in the chain, it's inserted between the static
 * `BoostConfig::configure()` and the rest of the chain.
 *
 * ## Format preservation
 *
 * Printing is format-preserving (php-parser's `printFormatPreserving` mode):
 * the original file is parsed into a pristine `$oldStmts` tree and a cloned
 * `$newStmts` tree, the clone is modified, and the printer diffs the two so
 * every untouched node is reproduced byte-for-byte from the original tokens.
 *
 * Consequences:
 * - The header docblock, inline comments, `use` imports, and the fluent
 *   chain's line layout all survive a `boost:install` / `boost:scan` rewrite.
 * - Only the three arrays this writer rebuilds (`withAgents`,
 *   `withAllowedVendors`, `withDisabledEmitters`) are re-printed —
 *   {@see BoostConfigPrinter} expands a non-empty one to the multi-line,
 *   trailing-comma shape; an empty one stays `[]`.
 * - The chain's line layout is whatever the source already had: the
 *   starter template ships it multi-line, so a starter-generated config
 *   round-trips cleanly. A chain that reaches the writer collapsed onto
 *   one line (e.g. a config last written by a pre-format-preserving
 *   boost-core) stays collapsed — re-running the picker won't reflow it.
 *   Regenerate via `boost:install` (delete `boost.php` first) for the
 *   canonical layout.
 * - A method *inserted* into a chain that didn't already contain it (only
 *   happens for hand-stripped configs — the starter ships all three) is
 *   printed by php-parser's insertion heuristic; the result is clean when
 *   the surrounding chain is already multi-line.
 */
final readonly class BoostConfigWriter
{
    public function __construct(
        private BoostConfigPrinter $printer = new BoostConfigPrinter(),
    ) {}

    /**
     * @param  list<Agent>  $agents
     * @param  list<string>  $allowedVendors
     * @param  list<string>  $disabledEmitters
     * @param  list<string>|null  $tags  Normalized lowercase tag strings to declare via `withTags(...)`.
     *                                   `null` = leave the existing `withTags()` call (if any) untouched
     *                                   — the install picker passes null when there's nothing to pick
     *                                   so it can't accidentally clear a hand-curated tag list.
     *                                   `[]` = remove `withTags(...)` entirely (operator unchecked everything).
     *                                   Non-empty = write `withTags(<args>)` with each entry rendered as
     *                                   `Tag::CaseName` when it matches a `Tag` enum case, raw string otherwise.
     *
     * @throws BoostConfigWriteException
     */
    public function update(
        string $configPath,
        array $agents,
        array $allowedVendors,
        array $disabledEmitters,
        ?array $tags = null,
    ): void {
        if (! is_file($configPath)) {
            throw new BoostConfigWriteException($configPath, 'file does not exist.');
        }

        $source = (string) file_get_contents($configPath);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $oldStmts = $parser->parse($source);
        } catch (Error $error) {
            throw new BoostConfigWriteException($configPath, 'parse error: ' . $error->getMessage());
        }

        if ($oldStmts === null) {
            throw new BoostConfigWriteException($configPath, 'parser returned no statements.');
        }

        $oldTokens = $parser->getTokens();

        // Emit `Agent::X` short when the host config imports the enum (the
        // starter does), fully-qualified otherwise.
        $agentAlias = $this->importedAgentName($oldStmts);

        // Clone the tree so $oldStmts stays pristine — printFormatPreserving
        // diffs the modified clone against it to reproduce untouched nodes
        // verbatim from the original tokens.
        $newStmts = (new NodeTraverser(new CloningVisitor()))->traverse($oldStmts);

        $return = (new NodeFinder())->findFirstInstanceOf($newStmts, Return_::class);
        if (! $return instanceof Return_) {
            throw new BoostConfigWriteException($configPath, 'no `return` statement found.');
        }

        // A bare `return BoostConfig::configure();` has no MethodCall yet — wrap it
        // in a synthetic one so the rest of the pipeline can handle a single shape.
        if ($return->expr instanceof StaticCall && $this->isBoostConfigConfigure($return->expr)) {
            $return->expr = new MethodCall(
                var: $return->expr,
                name: new Identifier('withAgents'),
                args: [new Arg($this->agentsToArray([], $agentAlias))],
            );
        }

        if (! $return->expr instanceof MethodCall || ! $this->chainRootsAtBoostConfigConfigure($return->expr)) {
            throw new BoostConfigWriteException(
                $configPath,
                'could not locate `return BoostConfig::configure()->...;` shape. Hand-edit and re-run.',
            );
        }

        $this->setOrInsert($return, 'withAgents', $this->agentsToArray($agents, $agentAlias));
        $this->setOrInsert($return, 'withAllowedVendors', $this->stringsToArray($allowedVendors));
        $this->setOrInsert($return, 'withDisabledEmitters', $this->stringsToArray($disabledEmitters));

        // Tags: only touch the chain when the caller explicitly passes a
        // list (the install picker passes null when there's nothing to
        // pick from). Empty list = operator wants the tag set cleared.
        if ($tags !== null) {
            // Read the alias from $oldStmts — `Stmt[]` typed straight off
            // the parser. $newStmts is the cloned tree typed `Node[]`,
            // which PHPStan can't narrow to the Stmt[] $importedTagName
            // expects. Imports are read-only data; the pristine tree
            // is the right source.
            $tagAlias = $this->importedTagName($oldStmts);
            if ($tags === []) {
                $this->removeFromChain($return, 'withTags');
            } else {
                $this->setOrInsertVariadic($return, 'withTags', $this->tagsToArgs($tags, $tagAlias));
            }
        }

        $newSource = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if (file_put_contents($configPath, $newSource) === false) {
            throw new BoostConfigWriteException($configPath, 'failed to write updated file.');
        }
    }

    private function isBoostConfigConfigure(StaticCall $call): bool
    {
        if (! $call->class instanceof Name) {
            return false;
        }

        $className = $call->class->toString();
        $isBoostConfig = in_array($className, [
            BoostConfig::class,
            'BoostConfig',
        ], true);

        $isConfigure = $call->name instanceof Identifier && $call->name->name === 'configure';

        return $isBoostConfig && $isConfigure;
    }

    private function chainRootsAtBoostConfigConfigure(MethodCall $call): bool
    {
        $current = $call;
        while (true) {
            $receiver = $current->var;
            if ($receiver instanceof MethodCall) {
                $current = $receiver;

                continue;
            }

            if ($receiver instanceof StaticCall) {
                return $this->isBoostConfigConfigure($receiver);
            }

            return false;
        }
    }

    /**
     * Replace the method's array arg if it exists in the chain, or insert it at the chain root.
     * `$return->expr` may be rebound when inserting — we update it via reference.
     */
    private function setOrInsert(Return_ $return, string $methodName, Array_ $array): void
    {
        $chain = $return->expr;
        if (! $chain instanceof MethodCall) {
            return;
        }

        $target = $this->findMethodInChain($chain, $methodName);

        if ($target instanceof MethodCall) {
            $target->args = [new Arg($array)];

            return;
        }

        // Insert as innermost method (between configure() and the first existing ->with*()).
        $current = $chain;
        while ($current->var instanceof MethodCall) {
            $current = $current->var;
        }

        $original = $current->var; // StaticCall
        $current->var = new MethodCall(
            var: $original,
            name: new Identifier($methodName),
            args: [new Arg($array)],
        );
    }

    private function findMethodInChain(MethodCall $outermost, string $methodName): ?MethodCall
    {
        $current = $outermost;
        while (true) {
            if ($current->name instanceof Identifier && $current->name->name === $methodName) {
                return $current;
            }

            if ($current->var instanceof MethodCall) {
                $current = $current->var;

                continue;
            }

            return null;
        }
    }

    /**
     * The local name the file uses for the {@see Agent} enum — the alias of
     * a `use SanderMuller\BoostCore\Enums\Agent [as X];` import, or `null`
     * when the enum is not imported (caller then emits it fully-qualified).
     *
     * @param  Stmt[]  $stmts
     */
    private function importedAgentName(array $stmts): ?string
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Use_) {
                continue;
            }

            if ($stmt->type !== Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($stmt->uses as $useItem) {
                if ($useItem->name->toString() === Agent::class) {
                    return $useItem->alias?->toString() ?? $useItem->name->getLast();
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Agent>  $agents
     */
    private function agentsToArray(array $agents, ?string $agentAlias): Array_
    {
        $items = [];
        foreach ($agents as $agent) {
            // Short `Agent::X` when the host config imports the enum (the
            // starter template does); fully-qualified otherwise so a
            // hand-written config lacking the import still resolves.
            $class = $agentAlias !== null
                ? new Name($agentAlias)
                : new FullyQualified(Agent::class);

            $items[] = new ArrayItem(
                new ClassConstFetch($class, new Identifier(strtoupper($agent->name))),
            );
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * @param  list<string>  $values
     */
    private function stringsToArray(array $values): Array_
    {
        $items = [];
        foreach ($values as $value) {
            $items[] = new ArrayItem(new String_($value));
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Variadic-args sibling of `setOrInsert`. `withTags(Tag::Php, 'jira')`
     * shape: each tag is a positional Arg, not an array.
     *
     * @param  list<Arg>  $args
     */
    private function setOrInsertVariadic(Return_ $return, string $methodName, array $args): void
    {
        $chain = $return->expr;
        if (! $chain instanceof MethodCall) {
            return;
        }

        $target = $this->findMethodInChain($chain, $methodName);
        if ($target instanceof MethodCall) {
            $target->args = $args;

            return;
        }

        $current = $chain;
        while ($current->var instanceof MethodCall) {
            $current = $current->var;
        }

        $original = $current->var; // StaticCall
        $current->var = new MethodCall(
            var: $original,
            name: new Identifier($methodName),
            args: $args,
        );
    }

    /**
     * Splice a `withX(...)` call out of the chain when present. Used when
     * the picker's empty selection should clear the existing call entirely
     * (e.g. operator unchecked every tag → no `withTags(...)` at all).
     */
    private function removeFromChain(Return_ $return, string $methodName): void
    {
        $chain = $return->expr;
        if (! $chain instanceof MethodCall) {
            return;
        }

        // The outermost call IS the one to remove — replace `$return->expr`
        // with the inner chain.
        if ($chain->name instanceof Identifier && $chain->name->name === $methodName) {
            $return->expr = $chain->var;

            return;
        }

        // Walk inward looking for the target.
        $current = $chain;
        while ($current->var instanceof MethodCall) {
            if ($current->var->name instanceof Identifier && $current->var->name->name === $methodName) {
                $current->var = $current->var->var;

                return;
            }

            $current = $current->var;
        }
    }

    /**
     * Render each tag string as `Tag::CaseName` when it matches an enum
     * case, raw string otherwise. The Tag enum is non-authoritative (its
     * docblock notes this); the string form is fully legal and the runtime
     * normalizes both into the same lowercase string.
     *
     * @param  list<string>  $tags
     * @return list<Arg>
     */
    private function tagsToArgs(array $tags, ?string $tagAlias): array
    {
        // Defensive normalization — mirror `Tag::normalize()` (trim +
        // lowercase) and drop empties + dupes so the written file
        // round-trips through BoostConfigBuilder cleanly. Without this,
        // ` PHP ` would write to disk verbatim and reload as `php`
        // (the builder normalizes on the read side), causing
        // write/read drift on a subsequent boost install.
        $seen = [];
        $args = [];
        foreach ($tags as $tag) {
            $normalized = strtolower(trim($tag));
            if ($normalized === '') {
                continue;
            }

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $args[] = new Arg($this->tagToExpr($normalized, $tagAlias));
        }

        return $args;
    }

    private function tagToExpr(string $tag, ?string $tagAlias): String_|ClassConstFetch
    {
        foreach (Tag::cases() as $case) {
            if ($case->value === $tag) {
                $class = $tagAlias !== null
                    ? new Name($tagAlias)
                    : new FullyQualified(Tag::class);

                return new ClassConstFetch($class, new Identifier($case->name));
            }
        }

        return new String_($tag);
    }

    /**
     * Mirror of {@see importedAgentName} for the `Tag` enum.
     *
     * @param  Stmt[]  $stmts
     */
    private function importedTagName(array $stmts): ?string
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Use_) {
                continue;
            }

            if ($stmt->type !== Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($stmt->uses as $useItem) {
                if ($useItem->name->toString() === Tag::class) {
                    return $useItem->alias?->toString() ?? $useItem->name->getLast();
                }
            }
        }

        return null;
    }
}
