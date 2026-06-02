<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use PhpParser\Error;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
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
 *
 * @internal
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
     *                                   Non-empty = write `withTags([<entries>])` with each entry rendered as
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
                $this->setOrInsert($return, 'withTags', $this->tagsToArray($tags, $tagAlias));
            }
        }

        $newSource = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if (file_put_contents($configPath, $newSource) === false) {
            throw new BoostConfigWriteException($configPath, 'failed to write updated file.');
        }
    }

    /**
     * Insert or replace `->withConventions([...])` in `boost.php`'s chain. The
     * conventions array is rendered as nested PHP-Parser AST via
     * {@see nestedArrayToAst()} — arbitrary depth, assoc keys, scalars, lists.
     *
     * Returns the would-write source. If `$dryRun` is false, also writes to
     * disk; if true, returns the source string without touching disk. The
     * `boost convert-conventions` command uses dry-run to preview the diff
     * before applying.
     *
     * Refusal:
     * - `BoostConfigWriteException` when `boost.php`'s shape doesn't match the
     *   canonical `return BoostConfig::configure()->...;` chain — the operator
     *   gets a precise error naming the shape and pointing them at manual
     *   migration. Same fail-closed pattern as {@see update()}.
     *
     * @param  array<string, mixed>  $conventions
     */
    public function writeConventions(string $configPath, array $conventions, bool $dryRun = false): string
    {
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
        $newStmts = (new NodeTraverser(new CloningVisitor()))->traverse($oldStmts);

        $return = (new NodeFinder())->findFirstInstanceOf($newStmts, Return_::class);
        if (! $return instanceof Return_) {
            throw new BoostConfigWriteException($configPath, 'no `return` statement found.');
        }

        // Bare `return BoostConfig::configure();` — wrap with a synthetic
        // ->withConventions([...]) call so the chain-modification path below
        // has a MethodCall to work with. Same shape `update()` uses for the
        // bare-call case.
        if ($return->expr instanceof StaticCall && $this->isBoostConfigConfigure($return->expr)) {
            $return->expr = new MethodCall(
                var: $return->expr,
                name: new Identifier('withConventions'),
                args: [new Arg($this->nestedArrayToAst($conventions))],
            );
        } elseif (! $return->expr instanceof MethodCall || ! $this->chainRootsAtBoostConfigConfigure($return->expr)) {
            throw new BoostConfigWriteException(
                $configPath,
                'unsupported boost.php shape: expected `return BoostConfig::configure()->...;` chain (single statement, no helper-function wrapping, no conditional branches). Hand-edit boost.php to add ->withConventions([...]) manually.',
            );
        } else {
            $this->setOrInsert($return, 'withConventions', $this->nestedArrayToAst($conventions));
        }

        $newSource = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if (! $dryRun && file_put_contents($configPath, $newSource) === false) {
            throw new BoostConfigWriteException($configPath, 'failed to write updated file.');
        }

        return $newSource;
    }

    /**
     * Recursively convert a PHP nested array into a PhpParser AST `Array_`
     * expression. Handles:
     * - Lists (numeric keys 0..N-1): emitted as `[v1, v2, v3]` with no keys.
     * - Assoc arrays: emitted as `['k' => v, ...]` with string keys.
     * - Scalar leaves: int / float / string / bool / null.
     * - Nested arrays: recursive call.
     *
     * Used by {@see writeConventions()} to render `withConventions([...])`
     * arrays into boost.php AST. Other PHP value types (objects, resources,
     * closures) are unsupported and throw — operators don't put those in
     * Project Conventions arrays.
     *
     * @param  array<mixed, mixed>  $values
     */
    private function nestedArrayToAst(array $values): Array_
    {
        $isList = array_is_list($values);
        $items = [];

        foreach ($values as $key => $value) {
            $valueExpr = $this->scalarOrArrayToAst($value);
            $items[] = $isList
                ? new ArrayItem($valueExpr)
                : new ArrayItem($valueExpr, $this->scalarKeyToAst($key));
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Convert a single PHP value (scalar or nested array) to a PhpParser
     * expression. Recursion bridge for {@see nestedArrayToAst()}.
     */
    private function scalarOrArrayToAst(mixed $value): Expr
    {
        if (is_array($value)) {
            return $this->nestedArrayToAst($value);
        }

        if (is_bool($value)) {
            return new ConstFetch(new Name($value ? 'true' : 'false'));
        }

        if ($value === null) {
            return new ConstFetch(new Name('null'));
        }

        if (is_int($value)) {
            return new Int_($value);
        }

        if (is_float($value)) {
            return new Float_($value);
        }

        if (is_string($value)) {
            return new String_($value);
        }

        throw new BoostConfigWriteException(
            '',
            sprintf('unsupported value type in withConventions array: %s. Supported leaves are string, int, float, bool, null, and nested arrays.', get_debug_type($value)),
        );
    }

    /**
     * Array-key AST. Conventions arrays use string assoc keys only — int keys
     * are list indices (handled separately by the array_is_list branch).
     */
    private function scalarKeyToAst(mixed $key): Expr
    {
        if (is_string($key)) {
            return new String_($key);
        }

        if (is_int($key)) {
            return new Int_($key);
        }

        throw new BoostConfigWriteException(
            '',
            sprintf('unsupported array key type in withConventions: %s. Keys must be string or int.', get_debug_type($key)),
        );
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
     */
    private function tagsToArray(array $tags, ?string $tagAlias): Array_
    {
        // Defensive normalization — mirror `Tag::normalize()` (trim +
        // lowercase) and drop empties + dupes so the written file
        // round-trips through BoostConfigBuilder cleanly. Without this,
        // ` PHP ` would write to disk verbatim and reload as `php`
        // (the builder normalizes on the read side), causing
        // write/read drift on a subsequent boost install.
        $seen = [];
        $items = [];
        foreach ($tags as $tag) {
            $normalized = strtolower(trim($tag));
            if ($normalized === '') {
                continue;
            }

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $items[] = new ArrayItem($this->tagToExpr($normalized, $tagAlias));
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
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
