<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
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
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

/**
 * AST editor for `withRemoteSkills([...])` in `boost.php`.
 *
 * Sibling of {@see BoostConfigWriter}, deliberately separate rather than
 * another method on it: every array that writer touches is rebuilt wholesale
 * from the caller's list, whereas this one is edited ELEMENT-WISE. It has to
 * recognise the entries already present, find the one matching
 * `(source, mode)`, leave the others byte-for-byte alone, and refuse the whole
 * operation the moment it meets an element it cannot read back. That is a
 * different job with its own failure mode, and folding it in would push the
 * writer well past its complexity budget.
 *
 * Same guarantees as its sibling: format-preserving printing, and fail-closed
 * refusal rather than a rewrite that could destroy hand-authored config.
 *
 * @internal
 */
final readonly class RemoteSkillsWriter
{
    public function __construct(
        private BoostConfigPrinter $printer = new BoostConfigPrinter(),
    ) {}

    /**
     * Insert or update one entry, returning the resulting file contents.
     *
     * Takes a built {@see RemoteSkillSource} rather than loose strings so the
     * value object's own rules — `<owner>/<repo>` shape, release-tag-only
     * bundle versions, duplicate skill names, mixed modes — are enforced
     * BEFORE anything reaches disk instead of at the next config load.
     *
     * @throws BoostConfigWriteException
     */
    public function write(string $configPath, RemoteSkillSource $source, bool $dryRun = false): string
    {
        return $this->edit($configPath, $source->source, $source->mode(), $source, $dryRun);
    }

    /**
     * Drop the `(source, mode)` entry, and the whole call with it when it was
     * the last one.
     *
     * Separate from {@see write()} because "declare nothing" cannot be
     * expressed as a {@see RemoteSkillSource} — its constructor rejects an
     * empty skill list. Removing a source that isn't declared is a no-op, not
     * an error, so a repeated deselect behaves the same each time.
     *
     * @throws BoostConfigWriteException
     */
    public function remove(string $configPath, string $source, string $mode, bool $dryRun = false): string
    {
        return $this->edit($configPath, $source, $mode, null, $dryRun);
    }

    /**
     * @param  RemoteSkillSource|null  $replacement  null = remove the matched entry
     *
     * @throws BoostConfigWriteException
     */
    private function edit(
        string $configPath,
        string $source,
        string $mode,
        ?RemoteSkillSource $replacement,
        bool $dryRun,
    ): string {
        if (! is_file($configPath)) {
            throw new BoostConfigWriteException($configPath, 'file does not exist.');
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $oldStmts = $parser->parse((string) file_get_contents($configPath));
        } catch (Error $error) {
            throw new BoostConfigWriteException($configPath, 'parse error: ' . $error->getMessage());
        }

        if ($oldStmts === null) {
            throw new BoostConfigWriteException($configPath, 'parser returned no statements.');
        }

        $oldTokens = $parser->getTokens();
        $alias = self::importedName($oldStmts);

        // Clone so $oldStmts stays pristine — printFormatPreserving diffs the
        // two trees to reproduce untouched nodes from the original tokens.
        $newStmts = (new NodeTraverser(new CloningVisitor()))->traverse($oldStmts);
        $chain = $this->chain($configPath, $newStmts);

        $existing = $this->findInChain($chain, 'withRemoteSkills');
        $items = $existing instanceof MethodCall
            ? $this->readItems($configPath, $existing, $alias, $replacement)
            : [];

        $matchIndex = $this->indexOf($items, $source, $mode, $alias);

        if ($replacement === null && $matchIndex === null) {
            return (string) file_get_contents($configPath);
        }

        $items = $this->apply($items, $matchIndex, $replacement, $alias);
        $this->store($chain, $items);

        $newSource = $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if (! $dryRun && file_put_contents($configPath, $newSource) === false) {
            throw new BoostConfigWriteException($configPath, 'failed to write updated file.');
        }

        return $newSource;
    }

    /**
     * @param  list<ArrayItem>  $items
     * @return list<ArrayItem>
     */
    private function apply(array $items, ?int $matchIndex, ?RemoteSkillSource $replacement, ?string $alias): array
    {
        if ($replacement === null) {
            unset($items[(int) $matchIndex]);

            return array_values($items);
        }

        $entry = new ArrayItem(self::toAst($replacement, $alias));
        if ($matchIndex === null) {
            return [...$items, $entry];
        }

        $items[$matchIndex] = $entry;

        return array_values($items);
    }

    /**
     * Write the items back, splicing `withRemoteSkills()` out entirely when
     * nothing is left rather than leaving an empty call behind.
     *
     * @param  list<ArrayItem>  $items
     */
    private function store(Return_ $chain, array $items): void
    {
        if ($items === []) {
            $this->removeFromChain($chain, 'withRemoteSkills');

            return;
        }

        $array = new Array_($items, ['kind' => Array_::KIND_SHORT]);
        $call = $chain->expr instanceof MethodCall ? $this->findInChain($chain, 'withRemoteSkills') : null;

        if ($call instanceof MethodCall) {
            $call->args = [new Arg($array)];

            return;
        }

        // Absent from the chain: insert innermost, matching BoostConfigWriter's
        // placement. The starter template ships `->withRemoteSkills([])`, so
        // this only happens on a config that dropped it by hand.
        $current = $chain->expr;
        if (! $current instanceof MethodCall) {
            return;
        }

        while ($current->var instanceof MethodCall) {
            $current = $current->var;
        }

        $current->var = new MethodCall(
            var: $current->var,
            name: new Identifier('withRemoteSkills'),
            args: [new Arg($array)],
        );
    }

    /**
     * The `return BoostConfig::configure()->...;` statement, wrapping a bare
     * `configure()` call so the rest of the pipeline sees one shape.
     *
     * @param  Node[]  $stmts  the CLONED tree, typed as plain nodes by the traverser
     *
     * @throws BoostConfigWriteException
     */
    private function chain(string $configPath, array $stmts): Return_
    {
        $return = (new NodeFinder())->findFirstInstanceOf($stmts, Return_::class);
        if (! $return instanceof Return_) {
            throw new BoostConfigWriteException($configPath, 'no `return` statement found.');
        }

        if ($return->expr instanceof StaticCall && $this->isConfigure($return->expr)) {
            $return->expr = new MethodCall(
                var: $return->expr,
                name: new Identifier('withRemoteSkills'),
                args: [new Arg(new Array_([], ['kind' => Array_::KIND_SHORT]))],
            );
        }

        if (! $return->expr instanceof MethodCall || ! $this->rootsAtConfigure($return->expr)) {
            throw new BoostConfigWriteException(
                $configPath,
                'could not locate `return BoostConfig::configure()->...;` shape. Hand-edit and re-run.',
            );
        }

        return $return;
    }

    private function isConfigure(StaticCall $call): bool
    {
        return $call->class instanceof Name
            && $call->name instanceof Identifier
            && $call->name->name === 'configure'
            && $call->class->getLast() === 'BoostConfig';
    }

    private function rootsAtConfigure(MethodCall $outermost): bool
    {
        $current = $outermost;
        while ($current->var instanceof MethodCall) {
            $current = $current->var;
        }

        return $current->var instanceof StaticCall && $this->isConfigure($current->var);
    }

    private function findInChain(Return_ $chain, string $methodName): ?MethodCall
    {
        $current = $chain->expr;
        while ($current instanceof MethodCall) {
            if ($current->name instanceof Identifier && $current->name->name === $methodName) {
                return $current;
            }

            $current = $current->var;
        }

        return null;
    }

    private function removeFromChain(Return_ $chain, string $methodName): void
    {
        $outermost = $chain->expr;
        if (! $outermost instanceof MethodCall) {
            return;
        }

        if ($outermost->name instanceof Identifier && $outermost->name->name === $methodName) {
            $chain->expr = $outermost->var;

            return;
        }

        $current = $outermost;
        while ($current->var instanceof MethodCall) {
            if ($current->var->name instanceof Identifier && $current->var->name->name === $methodName) {
                $current->var = $current->var->var;

                return;
            }

            $current = $current->var;
        }
    }

    /**
     * The array's elements, refusing the whole edit on anything this writer
     * cannot rewrite — a raw constructor, a variable, a spread. Rewriting an
     * array we can't read back would silently destroy hand-authored config, so
     * the message names the line and hands over a snippet to paste instead.
     *
     * @return list<ArrayItem>
     *
     * @throws BoostConfigWriteException
     */
    private function readItems(string $configPath, MethodCall $call, ?string $alias, ?RemoteSkillSource $replacement): array
    {
        $arg = $call->args[0] ?? null;
        if (! $arg instanceof Arg || ! $arg->value instanceof Array_) {
            throw new BoostConfigWriteException($configPath, sprintf(
                'withRemoteSkills() on line %d does not take a literal array. Hand-edit boost.php to add:%s',
                $call->getStartLine(),
                self::pasteHint($replacement, $alias),
            ));
        }

        $items = [];
        foreach ($arg->value->items as $item) {
            if (! $item instanceof ArrayItem || self::readCall($item->value, $alias) === null) {
                throw new BoostConfigWriteException($configPath, sprintf(
                    'withRemoteSkills() contains an entry on line %d that is not a literal '
                    . 'RemoteSkillSource::githubBundle(...) or ::githubPath(...) call, so rewriting the array '
                    . 'could destroy it. Nothing was written. Hand-edit boost.php to add:%s',
                    $item?->getStartLine() ?? $call->getStartLine(),
                    self::pasteHint($replacement, $alias),
                ));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Which element declares `(source, mode)`, ignoring its version — matching
     * on version would append a second entry for the same repo on every
     * re-pin, and that is exactly the collision `RemoteSkillIngester` rejects.
     *
     * @param  list<ArrayItem>  $items
     */
    private function indexOf(array $items, string $source, string $mode, ?string $alias): ?int
    {
        foreach ($items as $index => $item) {
            $read = self::readCall($item->value, $alias);
            if ($read !== null && $read['source'] === $source && $read['mode'] === $mode) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Render a source as the factory call that reproduces it: bundle skills as
     * a plain name list (the asset name is derived from the name), path skills
     * as a `name => path` map.
     */
    private static function toAst(RemoteSkillSource $source, ?string $alias): StaticCall
    {
        $isBundle = $source->mode() === RemoteSkillSource::MODE_BUNDLE;

        $skillItems = [];
        foreach ($source->skills as $skill) {
            $skillItems[] = $isBundle
                ? new ArrayItem(new String_($skill->name))
                : new ArrayItem(new String_((string) $skill->path), new String_($skill->name));
        }

        return new StaticCall(
            class: $alias !== null ? new Name($alias) : new FullyQualified(RemoteSkillSource::class),
            name: new Identifier($isBundle ? 'githubBundle' : 'githubPath'),
            args: [
                new Arg(new String_($source->source)),
                new Arg(new String_($source->version)),
                new Arg(new Array_($skillItems, ['kind' => Array_::KIND_SHORT])),
            ],
        );
    }

    /**
     * Read a `RemoteSkillSource::githubBundle('a/b', 'v1', [...])` expression,
     * or null for anything else: another class, a dynamic call, non-literal
     * arguments.
     *
     * @return array{source: string, mode: string}|null
     */
    private static function readCall(Expr $expr, ?string $alias): ?array
    {
        if (! $expr instanceof StaticCall || ! $expr->class instanceof Name || ! $expr->name instanceof Identifier) {
            return null;
        }

        $className = $expr->class->toString();
        if ($className !== RemoteSkillSource::class && ($alias === null || $className !== $alias)) {
            return null;
        }

        $mode = match ($expr->name->name) {
            'githubBundle' => RemoteSkillSource::MODE_BUNDLE,
            'githubPath' => RemoteSkillSource::MODE_PATH,
            default => null,
        };

        if ($mode === null) {
            return null;
        }

        $args = $expr->getArgs();
        if (count($args) !== 3
            || ! $args[0]->value instanceof String_
            || ! $args[1]->value instanceof String_
            || ! $args[2]->value instanceof Array_
        ) {
            return null;
        }

        return ['source' => $args[0]->value->value, 'mode' => $mode];
    }

    /**
     * The local name the config uses for {@see RemoteSkillSource}, or null
     * when it isn't imported — the caller then emits it fully-qualified,
     * mirroring how `BoostConfigWriter` handles the `Agent` and `Tag` enums.
     *
     * @param  Stmt[]  $stmts
     */
    private static function importedName(array $stmts): ?string
    {
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Use_ || $stmt->type !== Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($stmt->uses as $useItem) {
                if ($useItem->name->toString() === RemoteSkillSource::class) {
                    return $useItem->alias?->toString() ?? $useItem->name->getLast();
                }
            }
        }

        return null;
    }

    /**
     * The line an operator can paste when this writer refuses. A removal has
     * nothing to add, so it says so instead.
     */
    private static function pasteHint(?RemoteSkillSource $source, ?string $alias): string
    {
        if ($source === null) {
            return ' (the entry you wanted removed).';
        }

        $isBundle = $source->mode() === RemoteSkillSource::MODE_BUNDLE;

        $skills = [];
        foreach ($source->skills as $skill) {
            $skills[] = $isBundle
                ? sprintf("'%s'", $skill->name)
                : sprintf("'%s' => '%s'", $skill->name, (string) $skill->path);
        }

        return sprintf(
            "\n\n    %s::%s('%s', '%s', [%s]),",
            $alias ?? '\\' . RemoteSkillSource::class,
            $isBundle ? 'githubBundle' : 'githubPath',
            $source->source,
            $source->version,
            implode(', ', $skills),
        );
    }
}
