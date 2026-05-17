<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
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
 */
final class BoostConfigWriter
{
    public function __construct(
        private readonly Standard $printer = new Standard(),
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
            $stmts = $parser->parse($source);
        } catch (Error $e) {
            throw new BoostConfigWriteException($configPath, 'parse error: ' . $e->getMessage());
        }

        if ($stmts === null) {
            throw new BoostConfigWriteException($configPath, 'parser returned no statements.');
        }

        $return = (new NodeFinder())->findFirstInstanceOf($stmts, Return_::class);
        if (! $return instanceof Return_) {
            throw new BoostConfigWriteException($configPath, 'no `return` statement found.');
        }

        // Normalise: if the return is a bare StaticCall, wrap it into a single-link MethodCall chain
        // so we can extend it uniformly.
        if ($return->expr instanceof StaticCall && $this->isBoostConfigConfigure($return->expr)) {
            // No methods yet — we'll just add them all as a fresh chain.
            $chain = $return->expr;
            $chain = new MethodCall($chain, new Node\Identifier('withAgents'), [new Arg($this->agentsToArray($agents))]);
            $chain = new MethodCall($chain, new Node\Identifier('withAllowedVendors'), [new Arg($this->stringsToArray($allowedVendors))]);
            $chain = new MethodCall($chain, new Node\Identifier('withDisabledEmitters'), [new Arg($this->stringsToArray($disabledEmitters))]);
            $return->expr = $chain;
        } elseif ($return->expr instanceof MethodCall && $this->chainRootsAtBoostConfigConfigure($return->expr)) {
            $this->setOrInsert($return, 'withAgents', $this->agentsToArray($agents));
            $this->setOrInsert($return, 'withAllowedVendors', $this->stringsToArray($allowedVendors));
            $this->setOrInsert($return, 'withDisabledEmitters', $this->stringsToArray($disabledEmitters));
        } else {
            throw new BoostConfigWriteException(
                $configPath,
                'could not locate `return BoostConfig::configure()->...;` shape. Hand-edit and re-run.',
            );
        }

        $newSource = $this->printer->prettyPrintFile($stmts);

        if (file_put_contents($configPath, $newSource) === false) {
            throw new BoostConfigWriteException($configPath, 'failed to write updated file.');
        }
    }

    private function isBoostConfigConfigure(StaticCall $call): bool
    {
        if (! $call->class instanceof Node\Name) {
            return false;
        }

        $className = $call->class->toString();
        $isBoostConfig = in_array($className, [
            'SanderMuller\\BoostCore\\Config\\BoostConfig',
            'BoostConfig',
        ], true);

        $isConfigure = $call->name instanceof Node\Identifier && $call->name->name === 'configure';

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

        if ($target !== null) {
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
            name: new Node\Identifier($methodName),
            args: [new Arg($array)],
        );
    }

    private function findMethodInChain(MethodCall $outermost, string $methodName): ?MethodCall
    {
        $current = $outermost;
        while (true) {
            if ($current->name instanceof Node\Identifier && $current->name->name === $methodName) {
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
     * @param  list<Agent>  $agents
     */
    private function agentsToArray(array $agents): Array_
    {
        $items = [];
        foreach ($agents as $agent) {
            // Use fully-qualified name so the file works regardless of whether
            // the host config imports the Agent enum.
            $items[] = new ArrayItem(
                new ClassConstFetch(
                    class: new Node\Name\FullyQualified(Agent::class),
                    name: new Node\Identifier(strtoupper($agent->name)),
                ),
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
