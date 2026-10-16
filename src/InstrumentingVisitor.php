<?php

declare(strict_types=1);

namespace Sonda;

use PhpParser\{Node, NodeFinder, NodeVisitor, NodeVisitorAbstract, Token};
use SplObjectStorage;
use PhpParser\Node\{AttributeGroup, Expr, Identifier, Name, Param, PropertyHook, PropertyItem, Scalar, StaticVar, Stmt};
use PhpParser\Node\Expr\{ArrowFunction, BinaryOp, ClassConstFetch, Closure, ConstFetch, Empty_, Isset_, Match_, NullsafeMethodCall, NullsafePropertyFetch, Ternary};
use PhpParser\Node\Stmt\{Block, Case_, Catch_, ClassLike, ClassMethod, Const_, Declare_, Do_, ElseIf_, Else_, EnumCase, Finally_, For_, Foreach_, Function_, GroupUse, HaltCompiler, If_, InlineHTML, Namespace_, Nop, TryCatch, Use_, While_};
use PhpParser\Node\Expr\BinaryOp\{BooleanAnd, BooleanOr, Coalesce, LogicalAnd, LogicalOr};
use PhpParser\Node\Scalar\MagicConst\{Dir, File};

use function str_starts_with, dirname;

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
    /** The probes refer to the class by its fully qualified name, whatever namespace the file is in. */
    private const string HITS = '\\' . Hits::class;

    /** @var list<Edit> */
    private array $edits = [];

    /** @var array<int, Probe> */
    private array $probes = [];

    private int $nextProbeId = 0;

    /** @var array<int, int> lines that already have a probe */
    private array $probedLines = [];

    /** Only the relative order of visits matters. */
    private int $visitIndex = 0;

    private string $namespace = '';

    private string $className = '';

    /** @var array{name: string, local: bool} local: holds a `$__sonda` reference (fast mode) */
    private array $scope = ['name' => '{main}', 'local' => false];

    /** @var SplObjectStorage<Node, string> the class name in force before each class node */
    private SplObjectStorage $enclosingClassNames;

    /** @var SplObjectStorage<Node, array{name: string, local: bool}> the scope in force before each function-like node */
    private SplObjectStorage $enclosingScopes;

    /** @var array<int, int> object ids of nodes where an undefined value gives no warning */
    private array $silentNodes = [];

    /** @param array<Token> $tokens */
    public function __construct(private readonly array $tokens, private readonly string $fileId, private readonly string $path, private readonly bool $strict, private readonly bool $fast)
    {
        $this->enclosingClassNames = new SplObjectStorage();
        $this->enclosingScopes = new SplObjectStorage();
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

        if ($this->isConstantContext($node)) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        match (true) {
            $node instanceof Namespace_ => $this->enterNamespace($node),
            $node instanceof Declare_ => $this->statementList($node->stmts ?? [], false),
            $node instanceof ClassLike => $this->enterClass($node),
            $node instanceof ClassMethod => $this->enterFunction($node, $this->className . '->' . $node->name->toString(), $node->stmts),
            $node instanceof Function_ => $this->enterFunction($node, $this->namespace . $node->name->toString(), $node->stmts),
            $node instanceof Closure => $this->enterFunction($node, '{closure}', $node->stmts),
            $node instanceof ArrowFunction => $this->enterArrowFunction($node),
            $node instanceof PropertyHook => $this->enterPropertyHook($node),
            $node instanceof If_ => $this->enterIf($node),
            $node instanceof ElseIf_,
            $node instanceof Do_ => $this->enterConditionalBody($node->cond, $node->stmts),
            $node instanceof Else_,
            $node instanceof While_,
            $node instanceof For_,
            $node instanceof Foreach_ => $this->body($node->stmts),
            $node instanceof Case_ => $this->enterCase($node),
            $node instanceof TryCatch,
            $node instanceof Block => $this->statementList($node->stmts, false),
            $node instanceof Finally_ => $this->statementList($node->stmts, true),
            $node instanceof Catch_ => $this->enterCatch($node),
            $node instanceof Coalesce => $this->enterCoalesce($node),
            $this->isShortCircuit($node) => $this->enterShortCircuit($node),
            $node instanceof NullsafeMethodCall,
            $node instanceof NullsafePropertyFetch => $this->enterNullsafe($node),
            $node instanceof Isset_ => $this->silence($node->vars),
            $node instanceof Empty_ => $this->silence([$node->expr]),
            $node instanceof Expr\AssignOp\Coalesce => $this->wrapExpression($node->expr, true),
            $node instanceof Ternary => $this->enterTernary($node),
            $node instanceof Match_ => $this->enterMatch($node),
            $node instanceof File => $this->replace($node, var_export($this->path, true)),
            $node instanceof Dir => $this->replace($node, var_export(dirname($this->path), true)),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (isset($this->enclosingClassNames[$node])) {
            $this->className = $this->enclosingClassNames[$node];
            unset($this->enclosingClassNames[$node]);
        }

        if (isset($this->enclosingScopes[$node])) {
            $this->scope = $this->enclosingScopes[$node];
            unset($this->enclosingScopes[$node]);
        }

        return null;
    }

    private function enterNamespace(Namespace_ $node): void
    {
        $this->namespace = $node->name instanceof Name ? $node->name->toString() . '\\' : '';

        $this->statementList($node->stmts, false);
    }

    private function enterClass(ClassLike $node): void
    {
        $this->enclosingClassNames[$node] = $this->className;
        $this->className = $node->name instanceof Identifier ? $this->namespace . $node->name->toString() : 'class@anonymous';
    }

    /** @param array<Node>|null $statements null for abstract and interface methods */
    private function enterFunction(Node $function, string $name, ?array $statements): void
    {
        $instrumentable = $this->instrumentable($statements ?? []);
        $local = $this->fast && $instrumentable !== [] && ! $this->isShortEcho($instrumentable[0]);

        $this->enterScope($function, $name, $local);

        if ($statements === null) {
            return;
        }

        if ($instrumentable === []) {
            $this->closingBraceProbe($function);

            return;
        }

        $reference = $local ? '$__sonda = &' . self::HITS . '::$current[\'' . $this->fileId . "'];" : '';

        $this->statementList($statements, true, $reference);
    }

    private function enterArrowFunction(ArrowFunction $node): void
    {
        $this->enterScope($node, '{closure}', false);

        if (! $node->byRef) {
            $this->wrapExpression($node->expr, true, $this->ownLines($node->expr));
        }
    }

    private function enterPropertyHook(PropertyHook $node): void
    {
        $this->enterScope($node, '{hook}', false);

        if (is_array($node->body)) {
            $this->statementList($node->body, true);
        }
    }

    private function enterScope(Node $function, string $name, bool $local): void
    {
        $this->enclosingScopes[$function] = $this->scope;
        $this->scope = ['name' => $name, 'local' => $local];
    }

    private function enterIf(If_ $node): void
    {
        $this->body($node->stmts);

        if ($this->strict && ! $node->else instanceof Else_ && ! $this->usesAlternativeSyntax($node)) {
            $line = $node->getEndLine();
            $id = $this->probe(new Probe(null, true, $this->currentFunction(), $line, $line));

            $this->close($node->getEndFilePos() + 1, ' else { ' . $this->probeCode($id) . '; }');
        }
    }

    /** @param array<Node> $statements */
    private function enterConditionalBody(Expr $condition, array $statements): void
    {
        $this->wrapExpression($condition, false);
        $this->body($statements);
    }

    private function enterCase(Case_ $node): void
    {
        // Constant cases may compile to a jump table: their line has no code of its own.
        if ($node->cond instanceof Expr && ! $node->cond instanceof ClassConstFetch && ! $node->cond instanceof ConstFetch) {
            $this->wrapExpression($node->cond, false);
        }

        $this->statementList($node->stmts, true);
    }

    private function enterCatch(Catch_ $node): void
    {
        $this->statementList($node->stmts, true);

        // The catch line itself runs when the exception is caught.
        $instrumentable = $this->instrumentable($node->stmts);
        $id = $this->probe(new Probe(
            $node->getStartLine(),
            $instrumentable === [],
            $this->currentFunction(),
            $node->getStartLine(),
            $node->getEndLine(),
        ));
        $position = $instrumentable !== [] ? $instrumentable[0]->getStartFilePos() : $node->getEndFilePos();

        $this->edits[] = Edit::opening($position, $this->probeCode($id) . ';');
    }

    private function enterCoalesce(Coalesce $node): void
    {
        $this->silence([$node->left]);
        $this->enterShortCircuit($node);
    }

    /**
     * The right operand runs only when the left one does not decide alone: that is one outcome.
     * In strict mode the other one, the left operand deciding alone (short-circuit), is probed
     * too, through a pass-through call around the left operand; not for `??`, whose left side
     * may be an undefined key that only the operator itself reads silently.
     */
    private function enterShortCircuit(BinaryOp $node): void
    {
        $isCoalesce = $node instanceof Coalesce;
        $leftNeedsLineProbe = ! isset($this->probedLines[$node->left->getStartLine()]) && ! $this->isShortCircuit($node->left);
        $probesShortCircuit = $this->strict && ! $isCoalesce;

        $this->wrapExpression($node->right, true);

        if ($probesShortCircuit) {
            $skip = $this->probe(new Probe(null, true, $this->currentFunction(), $node->left->getStartLine(), $node->left->getEndLine()));
            $decidesWhenTruthy = $node instanceof BooleanOr || $node instanceof LogicalOr ? 'true' : 'false';

            $this->edits[] = Edit::opening($node->left->getStartFilePos(), self::HITS . "::shortCircuit('" . $this->fileId . "', {$skip}, {$decidesWhenTruthy}, ");
        }

        if ($leftNeedsLineProbe) {
            // A leftmost operand alone on its line runs code on that line. The left side of `??`
            // must stay a plain variable, or an undefined key would warn: the whole `??` is wrapped.
            $this->wrapExpression($isCoalesce ? $node : $node->left, false);
        }

        if ($probesShortCircuit) {
            $this->close($node->left->getEndFilePos() + 1, ')');
        }
    }

    /**
     * `$value?->member` runs the member access only when the value is not null: that is the
     * outcome to probe (and, in strict mode, the null one too). Wrapping the value right before
     * its `?->` keeps the short-circuit of the rest of the chain.
     */
    private function enterNullsafe(NullsafeMethodCall|NullsafePropertyFetch $node): void
    {
        $function = $this->currentFunction();
        $start = $node->getStartLine();
        $end = $node->getEndLine();
        $nonNull = $this->probe(new Probe(null, true, $function, $start, $end));
        $null = $this->strict ? $this->probe(new Probe(null, true, $function, $start, $end)) : 'null';
        $silentValue = isset($this->silentNodes[spl_object_id($node)]) ? ' ?? null' : '';

        $this->edits[] = Edit::opening($node->var->getStartFilePos(), self::HITS . "::nullsafe('" . $this->fileId . "', {$nonNull}, {$null}, ");
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
        foreach (new NodeFinder()->find($nodes, static fn (): bool => true) as $node) {
            $id = spl_object_id($node);

            $this->silentNodes[$id] = $id;
        }
    }

    private function enterTernary(Ternary $node): void
    {
        if ($node->if instanceof Expr) {
            $this->wrapExpression($node->if, true);
        }

        $this->wrapExpression($node->else, true);
    }

    private function enterMatch(Match_ $node): void
    {
        // The end of a match is compiled on the line of its last arm: that line runs whenever
        // the match does, whichever arm is taken.
        if ($node->arms !== []) {
            $lastArmLine = $node->arms[array_key_last($node->arms)]->getStartLine();
            $id = $this->probe(new Probe($lastArmLine, false, $this->currentFunction(), $lastArmLine, $lastArmLine));

            $this->wrap($id, $node->cond);
        }

        foreach ($node->arms as $arm) {
            // A non-literal condition runs a comparison on its line, even when the arm is not taken.
            foreach ($arm->conds ?? [] as $condition) {
                if (! $condition instanceof Scalar) {
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

        $first = $statements[0];
        $previous = $this->previousSignificantToken($first->getStartTokenPos())->text;

        if ($previous === '{' || $previous === ':') {
            $this->statementList($statements, true);

            return;
        }

        if ($this->instrumentable($statements) === []) {
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
        $lastKey = array_key_last($statements);

        if ($lastKey === null) {
            return;
        }

        $lastLine = $statements[$lastKey]->getEndLine();
        $instrumentable = $this->instrumentable($statements);

        if ($instrumentable === []) {
            return;
        }

        foreach ($instrumentable as $index => $statement) {
            $isFirst = $index === 0;
            $id = $this->probe(new Probe(
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

            $code = ($isFirst ? $prefix : '') . $this->probeCode($id) . ';';

            // Inline HTML runs as an echo: its probe opens PHP mode for itself. The closing tag eats
            // one newline, so a newline the HTML starts with (at the top of the file) is given back.
            if ($statement instanceof InlineHTML) {
                $code = '<?php ' . $code . ' ?>' . $this->leadingNewline($this->tokens[$statement->getStartTokenPos()]->text);
            }

            $this->edits[] = Edit::opening($statement->getStartFilePos(), $code);
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

    private function leadingNewline(string $text): string
    {
        if (str_starts_with($text, "\r\n")) {
            return "\r\n";
        }

        return str_starts_with($text, "\n") ? "\n" : '';
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
        $id = $this->probe(new Probe($line, true, $this->currentFunction(), $line, $line));

        $this->edits[] = Edit::opening($function->getEndFilePos(), $this->probeCode($id) . ';');
    }

    /**
     * `if (...): ... endif;` ends with `endif` after its last statement. An unbraced if whose
     * statement is itself an alternative-syntax if ends together with it, and is not one.
     */
    private function usesAlternativeSyntax(If_ $node): bool
    {
        $children = [$node->cond, ...$node->stmts, ...$node->elseifs];
        $lastChild = $children[array_key_last($children)];
        $end = $node->getEndTokenPos();

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
        $lines = range($expression->getStartLine(), $expression->getEndLine());
        $functions = new NodeFinder()->find(
            $expression,
            static fn (Node $node): bool => $node instanceof Closure || $node instanceof ArrowFunction,
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
        if ($this->scope['local']) {
            return '$__sonda[' . $id . ']=1';
        }

        return self::HITS . '::$current[\'' . $this->fileId . "'][" . $id . ']=1';
    }

    private function currentFunction(): string
    {
        return $this->scope['name'];
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
     * Statements that can have a probe before them: declarations, `use`, `declare` and comments
     * cannot.
     *
     * @param array<Node> $statements
     *
     * @return list<Node>
     */
    private function instrumentable(array $statements): array
    {
        $instrumentable = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Nop || $statement instanceof Namespace_ || $statement instanceof Declare_
                || $statement instanceof HaltCompiler || $statement instanceof Use_ || $statement instanceof GroupUse
                || $statement instanceof ClassLike || $statement instanceof Function_ || $statement instanceof Const_) {
                continue;
            }

            $instrumentable[] = $statement;
        }

        return $instrumentable;
    }

    /** Constant-expression contexts: probes are not valid there. */
    private function isConstantContext(Node $node): bool
    {
        return $node instanceof Param || $node instanceof PropertyItem || $node instanceof Node\Const_
            || $node instanceof AttributeGroup || $node instanceof EnumCase || $node instanceof StaticVar;
    }

    /** @phpstan-assert-if-true Expr\BinaryOp $node */
    private function isShortCircuit(Node $node): bool
    {
        return $node instanceof BooleanAnd || $node instanceof BooleanOr
            || $node instanceof LogicalAnd || $node instanceof LogicalOr
            || $node instanceof Coalesce;
    }
}
