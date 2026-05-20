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
     *
     * @throws BoostConfigWriteException
     */
    public function update(
        string $configPath,
        array $agents,
        array $allowedVendors,
        array $disabledEmitters,
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
}
