<?php

declare(strict_types=1);

namespace CovProto;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

use function dirname;

/**
 * Walks the AST and records the edits (probe insertions) and the probe metadata of a file.
 *
 * Statements get a probe right before them (line coverage). Decision outcomes get a branch
 * probe: the first statement of a function, if/elseif/else, loop, case, catch or finally body,
 * and the right operand of short-circuit operators, the arms of ternaries and match.
 *
 * Opening edits at the same offset keep the order they are created in, which is already
 * outer before inner (the traversal enters parents first). Closing edits at the same offset
 * must go inner before outer, so they are ordered by the visit index, latest first.
 */
final class InstrumentingVisitor extends NodeVisitorAbstract
{
    /** @var list<Edit> */
    private array $edits;

    /** @var array<int, Probe> */
    private array $probes;

    private int $nextProbeId;

    /** @var array<int, int> lines that already have a probe */
    private array $probedLines;

    private int $visitIndex;

    private string $namespace;

    /** @var list<array{node: Node, name: string}> */
    private array $classes;

    /** @var non-empty-list<array{node: ?Node, name: string, local: bool}> local: holds a `$__cov` reference (fast mode) */
    private array $scopes;

    /** @var array<int, int> object ids of nodes where an undefined value gives no warning */
    private array $silentNodes;

    /** @param array<int, Token> $tokens */
    public function __construct(
        private readonly array $tokens,
        private readonly string $fileId,
        private readonly string $path,
        private readonly bool $strict,
        private readonly bool $fast,
    ) {
        $this->edits       = [];
        $this->probes      = [];
        $this->nextProbeId = 0;
        $this->probedLines = [];
        $this->visitIndex  = 0; // only the relative order of visits matters
        $this->namespace   = '';
        $this->classes     = [];
        $this->scopes      = [['node' => null, 'name' => '{main}', 'local' => false]];

        $this->silentNodes = [];
    }

    /** @return list<Edit> */
    public function edits(): array
    {
        return $this->edits;
    }

    /** @return array<int, Probe> */
    public function probes(): array
    {
        return $this->probes;
    }

    /** @param array<Node> $statements the file-level statements, which no node owns */
    public function instrumentTopLevel(array $statements): void
    {
        $this->statementList($statements, false);
    }

    public function enterNode(Node $node): ?int
    {
        $this->visitIndex++;

        if (self::isConstantContext($node)) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        match (true) {
            $node instanceof Stmt\Namespace_      => $this->enterNamespace($node),
            $node instanceof Stmt\Declare_        => $this->statementList($node->stmts ?? [], false),
            $node instanceof Stmt\ClassLike       => $this->enterClass($node),
            $node instanceof Stmt\ClassMethod     => $this->enterFunction($node, $this->currentClass() . '->' . $node->name->toString(), $node->stmts),
            $node instanceof Stmt\Function_       => $this->enterFunction($node, $this->namespace . $node->name->toString(), $node->stmts),
            $node instanceof Expr\Closure         => $this->enterFunction($node, '{closure}', $node->stmts),
            $node instanceof Expr\ArrowFunction   => $this->enterArrowFunction($node),
            $node instanceof Node\PropertyHook    => $this->enterPropertyHook($node),
            $node instanceof Stmt\If_             => $this->enterIf($node),
            $node instanceof Stmt\ElseIf_,
            $node instanceof Stmt\Do_             => $this->enterConditionalBody($node->cond, $node->stmts),
            $node instanceof Stmt\Else_,
            $node instanceof Stmt\While_,
            $node instanceof Stmt\For_,
            $node instanceof Stmt\Foreach_        => $this->body($node->stmts),
            $node instanceof Stmt\Case_           => $this->enterCase($node),
            $node instanceof Stmt\TryCatch,
            $node instanceof Stmt\Block           => $this->statementList($node->stmts, false),
            $node instanceof Stmt\Finally_        => $this->statementList($node->stmts, true),
            $node instanceof Stmt\Catch_          => $this->enterCatch($node),
            $node instanceof Expr\BinaryOp\Coalesce => $this->enterCoalesce($node),
            self::isShortCircuit($node)           => $this->enterShortCircuit($node),
            $node instanceof Expr\NullsafeMethodCall,
            $node instanceof Expr\NullsafePropertyFetch => $this->enterNullsafe($node),
            $node instanceof Expr\Isset_          => $this->silence($node->vars),
            $node instanceof Expr\Empty_          => $this->silence([$node->expr]),
            $node instanceof Expr\AssignOp\Coalesce => $this->wrapExpression($node->expr, true),
            $node instanceof Expr\Ternary         => $this->enterTernary($node),
            $node instanceof Expr\Match_          => $this->enterMatch($node),
            $node instanceof Node\Scalar\MagicConst\File => $this->replace($node, var_export($this->path, true)),
            $node instanceof Node\Scalar\MagicConst\Dir  => $this->replace($node, var_export(dirname($this->path), true)),
            default                               => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($this->classes !== [] && $this->classes[array_key_last($this->classes)]['node'] === $node) {
            array_pop($this->classes);
        }

        if ($this->scopes[array_key_last($this->scopes)]['node'] === $node) {
            array_pop($this->scopes);
        }

        return null;
    }

    private function enterNamespace(Stmt\Namespace_ $node): void
    {
        $this->namespace = $node->name !== null ? $node->name->toString() . '\\' : '';

        $this->statementList($node->stmts, false);
    }

    private function enterClass(Stmt\ClassLike $node): void
    {
        $name = $node->name !== null ? $this->namespace . $node->name->toString() : 'class@anonymous';

        $this->classes[] = ['node' => $node, 'name' => $name];
    }

    /** @param array<Node>|null $statements null for abstract and interface methods */
    private function enterFunction(Node $function, string $name, ?array $statements): void
    {
        $instrumentable = self::instrumentable($statements ?? []);
        $local          = $this->fast && $instrumentable !== [] && !$this->isShortEcho($instrumentable[0]);

        $this->scopes[] = ['node' => $function, 'name' => $name, 'local' => $local];

        if ($statements === null) {
            return;
        }

        if ($instrumentable === []) {
            $this->closingBraceProbe($function);

            return;
        }

        $reference = $local ? '$__cov = &\CovProto\Hits::$current[\'' . $this->fileId . '\'];' : '';

        $this->statementList($statements, true, $reference);
    }

    private function enterArrowFunction(Expr\ArrowFunction $node): void
    {
        $this->scopes[] = ['node' => $node, 'name' => '{closure}', 'local' => false];

        if (!$node->byRef) {
            $this->wrapExpression($node->expr, true, $this->ownLines($node->expr));
        }
    }

    private function enterPropertyHook(Node\PropertyHook $node): void
    {
        $this->scopes[] = ['node' => $node, 'name' => '{hook}', 'local' => false];

        if (is_array($node->body)) {
            $this->statementList($node->body, true);
        }
    }

    private function enterIf(Stmt\If_ $node): void
    {
        $this->body($node->stmts);

        if ($this->strict && $node->else === null && !$this->usesAlternativeSyntax($node)) {
            $line = $node->getEndLine();
            $id   = $this->probe(new Probe(null, true, $this->currentFunction(), $line, $line));

            $this->close($node->getEndFilePos() + 1, ' else { ' . $this->probeCode($id) . '; }');
        }
    }

    /** @param array<Node> $statements */
    private function enterConditionalBody(Expr $condition, array $statements): void
    {
        $this->wrapExpression($condition, false);
        $this->body($statements);
    }

    private function enterCase(Stmt\Case_ $node): void
    {
        // Constant cases may compile to a jump table: their line has no code of its own.
        if ($node->cond !== null && !$node->cond instanceof Expr\ClassConstFetch && !$node->cond instanceof Expr\ConstFetch) {
            $this->wrapExpression($node->cond, false);
        }

        $this->statementList($node->stmts, true);
    }

    private function enterCatch(Stmt\Catch_ $node): void
    {
        $this->statementList($node->stmts, true);

        // The catch line itself runs when the exception is caught.
        $instrumentable = self::instrumentable($node->stmts);
        $id             = $this->probe(new Probe(
            $node->getStartLine(),
            $instrumentable === [],
            $this->currentFunction(),
            $node->getStartLine(),
            $node->getEndLine(),
        ));
        $position = $instrumentable !== [] ? $instrumentable[0]->getStartFilePos() : $node->getEndFilePos();

        $this->edits[] = Edit::opening($position, $this->probeCode($id) . ';');
    }

    private function enterCoalesce(Expr\BinaryOp\Coalesce $node): void
    {
        $this->silence([$node->left]);
        $this->enterShortCircuit($node);
    }

    private function enterShortCircuit(Expr\BinaryOp $node): void
    {
        $isCoalesce         = $node instanceof Expr\BinaryOp\Coalesce;
        $leftNeedsLineProbe = !isset($this->probedLines[$node->left->getStartLine()]) && !self::isShortCircuit($node->left);

        $this->wrapExpression($node->right, true);

        if (!$leftNeedsLineProbe) {
            return;
        }

        // A leftmost operand alone on its line runs code on that line. The left side of `??`
        // must stay a plain variable, or an undefined key would warn: the whole `??` is wrapped.
        $this->wrapExpression($isCoalesce ? $node : $node->left, false);
    }

    /**
     * `$value?->member` runs the member access only when the value is not null: that is the
     * outcome to probe (and, in strict mode, the null one too). Wrapping the value right before
     * its `?->` keeps the short-circuit of the rest of the chain.
     */
    private function enterNullsafe(Expr\NullsafeMethodCall|Expr\NullsafePropertyFetch $node): void
    {
        $function    = $this->currentFunction();
        $start       = $node->getStartLine();
        $end         = $node->getEndLine();
        $nonNull     = $this->probe(new Probe(null, true, $function, $start, $end));
        $null        = $this->strict ? $this->probe(new Probe(null, true, $function, $start, $end)) : 'null';
        $silentValue = isset($this->silentNodes[spl_object_id($node)]) ? ' ?? null' : '';

        $this->edits[] = Edit::opening($node->var->getStartFilePos(), '\CovProto\Hits::nullsafe(\'' . $this->fileId . "', {$nonNull}, {$null}, ");
        $this->close($node->var->getEndFilePos() + 1, $silentValue . ')');
    }

    /**
     * Inside isset(), empty() and the left side of `??`, an undefined variable, key or property
     * gives no warning. Passed to a function it would: `?? null` keeps it silent.
     *
     * @param array<Node> $nodes
     */
    private function silence(array $nodes): void
    {
        foreach ((new NodeFinder)->find($nodes, static fn (): bool => true) as $node) {
            $id = spl_object_id($node);

            $this->silentNodes[$id] = $id;
        }
    }

    private function enterTernary(Expr\Ternary $node): void
    {
        if ($node->if !== null) {
            $this->wrapExpression($node->if, true);
        }

        $this->wrapExpression($node->else, true);
    }

    private function enterMatch(Expr\Match_ $node): void
    {
        // The end of a match is compiled on the line of its last arm: that line runs whenever
        // the match does, whichever arm is taken.
        if ($node->arms !== []) {
            $lastArmLine = $node->arms[array_key_last($node->arms)]->getStartLine();
            $id          = $this->probe(new Probe($lastArmLine, false, $this->currentFunction(), $lastArmLine, $lastArmLine));

            $this->wrap($id, $node->cond);
        }

        foreach ($node->arms as $arm) {
            // A non-literal condition runs a comparison on its line, even when the arm is not taken.
            foreach ($arm->conds ?? [] as $condition) {
                if (!$condition instanceof Node\Scalar) {
                    $this->wrapExpression($condition, false);
                }
            }

            $this->wrapExpression($arm->body, true);
        }
    }

    /**
     * Body of if/elseif/else/loops. An unbraced body (always a single statement) gets
     * braces, so its probe can go before it.
     *
     * @param array<Node> $statements
     */
    private function body(array $statements): void
    {
        if ($statements === []) {
            return;
        }

        $first    = $statements[0];
        $previous = $this->previousSignificantToken($first->getStartTokenPos())->text;

        if ($previous === '{' || $previous === ':') {
            $this->statementList($statements, true);

            return;
        }

        if (self::instrumentable($statements) === []) {
            return;
        }

        $this->statementList($statements, true, '{ ');
        $this->close($first->getEndFilePos() + 1, ' }');
    }

    /**
     * @param array<Node> $statements
     * @param string      $prefix     code placed before the probe of the first statement
     */
    private function statementList(array $statements, bool $isOutcome, string $prefix = ''): void
    {
        $instrumentable = self::instrumentable($statements);

        if ($instrumentable === []) {
            return;
        }

        $lastLine = $statements[array_key_last($statements)]->getEndLine();

        foreach ($instrumentable as $index => $statement) {
            $isFirst = $index === 0;
            $id      = $this->probe(new Probe(
                $statement->getStartLine(),
                $isOutcome && $isFirst,
                $this->currentFunction(),
                $isOutcome && $isFirst ? $statement->getStartLine() : 0,
                $isOutcome && $isFirst ? $lastLine : 0,
            ));

            // `<?=` opens PHP mode itself: a probe before it would be printed as HTML.
            if ($this->isShortEcho($statement)) {
                $this->wrap($id, $statement->exprs[0]);

                continue;
            }

            $this->edits[] = Edit::opening($statement->getStartFilePos(), ($isFirst ? $prefix : '') . $this->probeCode($id) . ';');
        }
    }

    /** @param list<int> $extraLines */
    private function wrapExpression(Expr $expression, bool $isBranch, array $extraLines = []): void
    {
        $id = $this->probe(new Probe(
            $expression->getStartLine(),
            $isBranch,
            $this->currentFunction(),
            $expression->getStartLine(),
            $expression->getEndLine(),
            $extraLines,
        ));

        $this->wrap($id, $expression);
    }

    /** `((probe) ? (expression) : null)`: the probe runs exactly when the expression is evaluated. */
    private function wrap(int $id, Expr $expression): void
    {
        $this->edits[] = Edit::opening($expression->getStartFilePos(), '((' . $this->probeCode($id) . ') ? (');
        $this->close($expression->getEndFilePos() + 1, ') : null)');
    }

    /**
     * Only an echo statement can start with `<?=`.
     *
     * @phpstan-assert-if-true Stmt\Echo_ $statement
     */
    private function isShortEcho(Node $statement): bool
    {
        return $this->tokens[$statement->getStartTokenPos()]->id === T_OPEN_TAG_WITH_ECHO;
    }

    /** The function ends on its closing brace: a body without statements runs only that line. */
    private function closingBraceProbe(Node $function): void
    {
        $line = $function->getEndLine();
        $id   = $this->probe(new Probe($line, true, $this->currentFunction(), $line, $line));

        $this->edits[] = Edit::opening($function->getEndFilePos(), $this->probeCode($id) . ';');
    }

    /**
     * `if (...): ... endif;` ends with `endif` after its last statement. An unbraced if whose
     * statement is itself an alternative-syntax if ends together with it, and is not one.
     */
    private function usesAlternativeSyntax(Stmt\If_ $node): bool
    {
        $children  = [$node->cond, ...$node->stmts, ...$node->elseifs];
        $lastChild = $children[array_key_last($children)];
        $end       = $node->getEndTokenPos();

        return $lastChild->getEndTokenPos() !== $end && $this->previousSignificantToken($end)->id === T_ENDIF;
    }

    private function replace(Node $node, string $text): void
    {
        $start = $node->getStartFilePos();

        $this->edits[] = Edit::opening($start, $text, $node->getEndFilePos() + 1 - $start);
    }

    /** @return list<int> lines of an expression, minus the lines of nested functions */
    private function ownLines(Expr $expression): array
    {
        $lines     = range($expression->getStartLine(), $expression->getEndLine());
        $functions = (new NodeFinder)->find(
            $expression,
            static fn (Node $node): bool => $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction,
        );

        foreach ($functions as $function) {
            $lines = array_diff($lines, range($function->getStartLine() + 1, $function->getEndLine()));
        }

        return array_values($lines);
    }

    private function probe(Probe $probe): int
    {
        $id = $this->nextProbeId++;

        if ($probe->line !== null) {
            $this->probedLines[$probe->line] = $probe->line;
        }

        $this->probes[$id] = $probe;

        return $id;
    }

    private function probeCode(int $id): string
    {
        if ($this->scopes[array_key_last($this->scopes)]['local']) {
            return '$__cov[' . $id . ']=1';
        }

        return '\CovProto\Hits::$current[\'' . $this->fileId . '\'][' . $id . ']=1';
    }

    private function currentFunction(): string
    {
        return $this->scopes[array_key_last($this->scopes)]['name'];
    }

    private function currentClass(): string
    {
        return $this->classes[array_key_last($this->classes)]['name'];
    }

    /** Closing edits of nodes visited later (inner ones) go first. */
    private function close(int $position, string $text): void
    {
        $this->edits[] = Edit::closing($position, -$this->visitIndex, $text);
    }

    /** Skips whitespace, comments and `?>`, which in templates sits between a brace and the HTML it opens. */
    private function previousSignificantToken(int $position): Token
    {
        do {
            $position--;
        } while ($this->tokens[$position]->isIgnorable() || $this->tokens[$position]->id === T_CLOSE_TAG);

        return $this->tokens[$position];
    }

    /**
     * Statements that can have a probe before them: declarations, `use`, `declare`, comments
     * and inline HTML cannot.
     *
     * @param array<Node> $statements
     *
     * @return list<Node>
     */
    private static function instrumentable(array $statements): array
    {
        $instrumentable = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Nop || $statement instanceof Stmt\Namespace_ || $statement instanceof Stmt\Declare_
                || $statement instanceof Stmt\HaltCompiler || $statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse
                || $statement instanceof Stmt\ClassLike || $statement instanceof Stmt\Function_ || $statement instanceof Stmt\Const_
                || $statement instanceof Stmt\InlineHTML) {
                continue;
            }

            $instrumentable[] = $statement;
        }

        return $instrumentable;
    }

    /** Constant-expression contexts: probes are not valid there. */
    private static function isConstantContext(Node $node): bool
    {
        return $node instanceof Node\Param || $node instanceof Node\PropertyItem || $node instanceof Node\Const_
            || $node instanceof Node\AttributeGroup || $node instanceof Stmt\EnumCase || $node instanceof Node\StaticVar;
    }

    /** @phpstan-assert-if-true Expr\BinaryOp $node */
    private static function isShortCircuit(Node $node): bool
    {
        return $node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd || $node instanceof Expr\BinaryOp\LogicalOr
            || $node instanceof Expr\BinaryOp\Coalesce;
    }
}
