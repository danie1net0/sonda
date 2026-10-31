<?php

declare(strict_types=1);

namespace Sonda;

use PhpParser\Node\Arg;
use PhpParser\{Node, NodeFinder, NodeVisitor, NodeVisitorAbstract, Token};
use SplObjectStorage;
use PhpParser\Node\{AttributeGroup, Expr, Identifier, Name, Param, PropertyHook, PropertyItem, Scalar, StaticVar, Stmt};
use PhpParser\Node\Expr\{ArrayDimFetch, ArrowFunction, BinaryOp, ClassConstFetch, Closure, ConstFetch, Empty_, FuncCall, Instanceof_, Isset_, Match_, MethodCall, New_, NullsafeMethodCall, NullsafePropertyFetch, StaticCall, Ternary, Variable};
use PhpParser\Node\Stmt\{Block, Case_, Catch_, ClassLike, ClassMethod, Const_, Declare_, Do_, ElseIf_, Else_, EnumCase, Finally_, For_, Foreach_, Function_, GroupUse, HaltCompiler, If_, InlineHTML, Namespace_, Nop, TryCatch, Use_, While_};
use PhpParser\Node\Expr\BinaryOp\{BooleanAnd, BooleanOr, Coalesce, LogicalAnd, LogicalOr};
use PhpParser\Node\Scalar\MagicConst\{Dir, File};

use function implode, in_array, preg_match, str_starts_with, substr, trim, dirname;

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

    private const string LIVEWIRE_EXTEND_BLADE = 'Livewire\\Mechanisms\\ExtendBlade\\ExtendBlade';

    private const array SUPERGLOBALS = ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'];

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
    private array $scope;

    /** @var SplObjectStorage<Node, string> the class name in force before each class node */
    private SplObjectStorage $enclosingClassNames;

    /** @var SplObjectStorage<Node, array{name: string, local: bool}> the scope in force before each function-like node */
    private SplObjectStorage $enclosingScopes;

    /** Compiled Blade view: the code Blade writes around the template is not probed as decisions. */
    private readonly bool $view;

    /** @var SplObjectStorage<Node, true> conditions Blade wrote around the template, left alone */
    private SplObjectStorage $bookkeeping;

    /** @var SplObjectStorage<Node, true> bodies Blade wrote around the template: lines, not outcomes */
    private SplObjectStorage $plainBodies;

    /** @var array<int, int> object ids of nodes where an undefined value gives no warning */
    private array $silentNodes = [];

    /**
     * @param array<Token> $tokens
     * @param string       $code      the source the tokens come from
     * @param string       $mainScope the function name of the file-level code: `{main}`, whose branches are
     *                                not reported, or the name of a function declared in the analysis copy
     */
    public function __construct(private readonly array $tokens, private readonly string $code, private readonly string $fileId, private readonly string $path, private readonly bool $strict, private readonly bool $fast, string $mainScope = '{main}')
    {
        $this->scope = ['name' => $mainScope, 'local' => false];
        $this->view = $mainScope !== '{main}';
        $this->bookkeeping = new SplObjectStorage();
        $this->plainBodies = new SplObjectStorage();
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

        if ($this->isConstantContext($node) || $this->isLivewireGuard($node) || isset($this->bookkeeping[$node]) || $this->isBladeDefault($node)) {
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
            $node instanceof Do_ => $this->enterConditionalBody($node, $node->cond, $node->stmts),
            $node instanceof Else_ => $this->body($node, $node->stmts, ! isset($this->plainBodies[$node])),
            $node instanceof Foreach_ => $this->body($node, $node->stmts, ! $this->isBladeLoop($node)),
            $node instanceof While_,
            $node instanceof For_ => $this->body($node, $node->stmts),
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

        if ($node->byRef) {
            $this->rewriteByReferenceArrowFunction($node);

            return;
        }

        $this->wrapExpression($node->expr, true, $this->ownLines($node->expr));
    }

    /**
     * `fn&(...) => expr` cannot hold a probe around its expression, which must stay a reference: it
     * becomes the closure it is sugar for, `function &(...) use (...) { probe; return expr; }`, with
     * the variables of the expression captured by value, as the arrow function captures them.
     */
    private function rewriteByReferenceArrowFunction(ArrowFunction $node): void
    {
        $fn = $this->nextTokenOfKind($node->getStartTokenPos(), T_FN);
        $signatureEnd = $node->returnType instanceof Node ? $node->returnType->getStartFilePos() : $node->expr->getStartFilePos();
        // From the `&` to the `=>` (or to the return type): `&(int $x) ` with its colon trimmed.
        $parameters = trim(rtrim(rtrim($this->sourceBetween($fn->pos + 2, $signatureEnd)), '=>:'));
        $returnType = $node->returnType instanceof Node ? ': ' . $this->sourceBetween($node->returnType->getStartFilePos(), $node->returnType->getEndFilePos() + 1) : '';
        $captured = $this->capturedVariables($node);
        $use = $captured === [] ? '' : ' use ($' . implode(', $', $captured) . ')';
        $line = $node->expr->getStartLine();
        $id = $this->probe(new Probe($line, true, $this->currentFunction(), $line, $node->expr->getEndLine(), $this->ownLines($node->expr)));

        $this->edits[] = Edit::opening($fn->pos, 'function ' . $parameters . $use . $returnType . ' { ' . $this->probeCode($id) . '; return ', $node->expr->getStartFilePos() - $fn->pos);
        $this->close($node->expr->getEndFilePos() + 1, '; }');
    }

    /**
     * The variables an arrow function takes from its scope: those its expression reads, minus its
     * parameters; the parameters of nested arrow functions and the bodies of nested closures do not
     * count, but what those closures `use` does.
     *
     * @return list<string>
     */
    private function capturedVariables(ArrowFunction $node): array
    {
        $found = [];
        $this->collectVariables($node->expr, ['this', ...$this->parameterNames($node->params)], $found);

        return $found;
    }

    /**
     * @param list<string> $excluded
     * @param list<string> $found
     */
    private function collectVariables(Node $node, array $excluded, array &$found): void
    {
        if ($node instanceof Closure) {
            foreach ($node->uses as $use) {
                $this->collectVariables($use->var, $excluded, $found);
            }

            return;
        }

        if ($node instanceof ArrowFunction) {
            $excluded = [...$excluded, ...$this->parameterNames($node->params)];
        }

        if ($node instanceof Variable && is_string($node->name) && ! in_array($node->name, $excluded, true) && ! in_array($node->name, $found, true) && ! in_array($node->name, self::SUPERGLOBALS, true)) {
            $found[] = $node->name;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;

            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node) {
                    $this->collectVariables($item, $excluded, $found);
                }
            }
        }
    }

    /**
     * @param array<Param> $parameters
     *
     * @return list<string>
     */
    private function parameterNames(array $parameters): array
    {
        $names = [];

        foreach ($parameters as $parameter) {
            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $names[] = $parameter->var->name;
            }
        }

        return $names;
    }

    private function nextTokenOfKind(int $position, int $kind): Token
    {
        while ($this->tokens[$position]->id !== $kind) {
            $position++;
        }

        return $this->tokens[$position];
    }

    private function sourceBetween(int $start, int $end): string
    {
        return substr($this->code, $start, $end - $start);
    }

    private function enterPropertyHook(PropertyHook $node): void
    {
        $this->enterScope($node, '{hook}', false);

        if (is_array($node->body)) {
            $this->statementList($node->body, true);
        } elseif ($node->body instanceof Expr && $node->byRef) {
            // `&get => expr;` must return a reference: it becomes `&get { probe; return expr; }`.
            $arrow = $this->nextTokenOfKind($node->getStartTokenPos(), T_DOUBLE_ARROW);
            $line = $node->body->getStartLine();
            $id = $this->probe(new Probe($line, true, $this->currentFunction(), $line, $node->body->getEndLine(), $this->ownLines($node->body)));
            $this->edits[] = Edit::opening($arrow->pos, '{ ' . $this->probeCode($id) . '; return', 2);
            $this->close($node->getEndFilePos() + 1, ' }');
        } elseif ($node->body instanceof Expr) {
            $this->wrapExpression($node->body, true, $this->ownLines($node->body));
        }
    }

    private function enterScope(Node $function, string $name, bool $local): void
    {
        $this->enclosingScopes[$function] = $this->scope;
        $this->scope = ['name' => $name, 'local' => $local];
    }

    private function enterIf(If_ $node): void
    {
        if ($node->elseifs === [] && $this->isBladeCondition($node->cond)) {
            $this->bookkeeping[$node->cond] = true;
            $this->body($node, $node->stmts, false);

            if ($node->else instanceof Else_) {
                $this->plainBodies[$node->else] = true;
            }

            return;
        }

        $this->body($node, $node->stmts);

        if (! $this->strict || $node->else instanceof Else_) {
            return;
        }

        $endif = $this->endifToken($node);

        if ($endif instanceof Token) {
            $id = $this->probe(new Probe(null, true, $this->currentFunction(), $endif->line, $endif->line));
            // An if without else right before the endif would take the else: as its own (dangling else);
            // an empty statement closes it first.
            $separator = $this->endsWithIfWithoutElse($node) ? '; ' : '';

            $this->close($endif->pos, $separator . 'else: ' . $this->probeCode($id) . '; ');

            return;
        }

        $line = $node->getEndLine();
        $id = $this->probe(new Probe(null, true, $this->currentFunction(), $line, $line));

        $this->close($node->getEndFilePos() + 1, ' else { ' . $this->probeCode($id) . '; }');
    }

    /** @param array<Node> $statements */
    private function enterConditionalBody(Node $owner, Expr $condition, array $statements): void
    {
        $this->wrapExpression($condition, false);
        $this->body($owner, $statements);
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
        if ($this->isBladeCondition($node->cond)) {
            $this->bookkeeping[$node->cond] = true;

            return;
        }

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
     * braces, so its probe can go before it. An empty body gets a probe of its own, with
     * no line: running it is an outcome, even though no line runs. A body Blade wrote around the
     * template is not an outcome: its lines are probed, the decision is not.
     *
     * @param array<Node> $statements
     */
    private function body(Node $owner, array $statements, bool $isOutcome = true): void
    {
        if ($statements === []) {
            if ($isOutcome) {
                $this->emptyBody($owner);
            }

            return;
        }

        $first = $statements[0];
        $previous = $this->previousSignificantToken($first->getStartTokenPos())->text;

        if ($previous === '{' || $previous === ':') {
            $this->statementList($statements, $isOutcome);

            return;
        }

        if ($this->instrumentable($statements) === []) {
            return;
        }

        $this->statementList($statements, $isOutcome, '{ ');
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

        $last = $statements[$lastKey];
        // The newline that ends inline HTML belongs to its line, not to the next one php-parser points at.
        $lastLine = $last->getEndLine() - ($last instanceof InlineHTML && str_ends_with($last->value, "\n") ? 1 : 0);
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

    /**
     * A branch probe inside an empty body: before its closing brace, before the `endif`-like
     * keyword of the alternative syntax, or after the `:` that opens an empty `else:`. A body
     * that is a lone `;` has nowhere to hold one.
     */
    private function emptyBody(Node $owner): void
    {
        $closing = $this->bodyClosing($owner);
        $position = match (true) {
            $closing->text === '}' => $closing->pos,
            $closing->text === '{', $closing->text === ':' => $closing->pos + 1,
            default => $this->alternativeClosingPosition($owner->getEndTokenPos()),
        };

        if ($position === null) {
            return;
        }

        $line = $closing->line;
        $id = $this->probe(new Probe(null, true, $this->currentFunction(), $line, $line));

        // A closing edit, added before the synthetic else of the same if: at the same position, it stays first.
        $this->close($position, $this->probeCode($id) . ';');
    }

    /**
     * The token that closes the body of a node: the brace a `do` opens with, the brace or colon
     * before the `elseif` or `else` that follows an `if`, or else the last token of the node.
     */
    private function bodyClosing(Node $owner): Token
    {
        if ($owner instanceof Do_) {
            return $this->tokens[$this->nextSignificantToken($owner->getStartTokenPos())];
        }

        $follower = $owner instanceof If_ ? ($owner->elseifs[0] ?? $owner->else) : null;

        if ($follower instanceof Node) {
            return $this->previousSignificantToken($follower->getStartTokenPos());
        }

        return $this->tokens[$owner->getEndTokenPos()];
    }

    /** The position of the `endif`, `endwhile`, `endfor` or `endforeach` that ends a body, when there is one. */
    private function alternativeClosingPosition(int $end): ?int
    {
        $token = $this->previousSignificantToken($end);

        return $token->is([T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH]) ? $token->pos : null;
    }

    private function nextSignificantToken(int $position): int
    {
        do {
            $position++;
        } while ($this->tokens[$position]->isIgnorable());

        return $position;
    }

    /** The function ends on its closing brace: a body without statements runs only that line. */
    private function closingBraceProbe(Node $function): void
    {
        $line = $function->getEndLine();
        $id = $this->probe(new Probe($line, true, $this->currentFunction(), $line, $line));

        $this->edits[] = Edit::opening($function->getEndFilePos(), $this->probeCode($id) . ';');
    }

    /** Whether the last statement before the `endif` is an if left without an else, braced or not. */
    private function endsWithIfWithoutElse(If_ $node): bool
    {
        $body = $node->elseifs === [] ? $node->stmts : $node->elseifs[array_key_last($node->elseifs)]->stmts;
        $last = $body === [] ? null : $body[array_key_last($body)];

        return $last instanceof If_ && ! $last->else instanceof Else_;
    }

    /**
     * The `endif` of an alternative-syntax if, which ends with it after its last statement. An
     * unbraced if whose statement is itself an alternative-syntax if ends together with it, and has none.
     */
    private function endifToken(If_ $node): ?Token
    {
        $children = [$node->cond, ...$node->stmts, ...$node->elseifs];
        $lastChild = $children[array_key_last($children)];
        $end = $node->getEndTokenPos();

        if ($lastChild->getEndTokenPos() === $end) {
            return null;
        }

        $token = $this->previousSignificantToken($end);

        return $token->id === T_ENDIF ? $token : null;
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

            // A shebang is dropped by PHP only as the very first bytes: a probe before it would print it.
            if ($statement instanceof InlineHTML && $statement->getStartFilePos() === 0 && str_starts_with($statement->value, '#!')) {
                continue;
            }

            $instrumentable[] = $statement;
        }

        return $instrumentable;
    }

    /** Constant-expression contexts: probes are not valid there. */
    /**
     * Livewire wraps every `@if`, `@unless` and `@foreach` of a compiled view in
     * `if (ExtendBlade::isRenderingLivewireComponent()): ... endif;`, which only prints markers and
     * tracks loops. Its outcome depends on how the view is rendered, not on the template: probing it
     * would give each directive a branch that no test can take. It is left alone, body included.
     */
    private function isLivewireGuard(Node $node): bool
    {
        return $node instanceof If_
            && $node->elseifs === [] && ! $node->else instanceof Else_
            && $node->cond instanceof StaticCall
            && $node->cond->class instanceof Name && $node->cond->class->toString() === self::LIVEWIRE_EXTEND_BLADE
            && $node->cond->name instanceof Identifier && $node->cond->name->toString() === 'isRenderingLivewireComponent';
    }

    /**
     * A condition Blade writes around the template, whose outcome depends on how the view is rendered
     * and not on the template: the save and restore of `$component`, `$attributes` and `$message`
     * around a component tag or `@error`, `$component->shouldRender()`, and the checks `@props` and
     * `@aware` make on `$__key` and the other variables Blade names with two underscores.
     */
    private function isBladeCondition(Expr $condition): bool
    {
        if (! $this->view) {
            return false;
        }

        return match (true) {
            $condition instanceof BooleanAnd => $this->isBladeCondition($condition->left) && $this->isBladeCondition($condition->right),
            $condition instanceof Isset_ => array_all($condition->vars, fn (Expr $variable): bool => $this->isBladeSaved($variable)),
            $condition instanceof Instanceof_ => $condition->expr instanceof Variable && $condition->expr->name === 'attributes',
            $condition instanceof MethodCall => $condition->var instanceof Variable && $condition->var->name === 'component'
                && $condition->name instanceof Identifier && $condition->name->toString() === 'shouldRender',
            $condition instanceof FuncCall => $condition->args !== [] && array_all($condition->getArgs(), fn (Arg $argument): bool => $this->isBladeVariable($argument->value)),
            default => false,
        };
    }

    /** `$component`, `$attributes` and `$message`, which Blade saves and restores, and their saved copies. */
    private function isBladeSaved(Expr $variable): bool
    {
        return $variable instanceof Variable && is_string($variable->name)
            && (in_array($variable->name, ['component', 'attributes', 'message'], true) || preg_match('/^__(component|attributes|message)Original/', $variable->name) === 1);
    }

    /** A variable Blade names with two underscores, `$$__key` included, or an element of one. */
    private function isBladeVariable(Expr $expression): bool
    {
        if ($expression instanceof ArrayDimFetch) {
            return $this->isBladeVariable($expression->var);
        }

        if (! $expression instanceof Variable) {
            return false;
        }

        return is_string($expression->name) ? str_starts_with($expression->name, '__') : $this->isBladeVariable($expression->name);
    }

    /**
     * The defaults Blade fills in: `$$__key ?? $__value` in `@props`, `$__key ??= ...` in `@livewire`,
     * `$__errorArgs[1] ?? 'default'` in `@error`, and `$attributes ??= new ComponentAttributeBag`.
     */
    private function isBladeDefault(Node $node): bool
    {
        if (! $this->view) {
            return false;
        }

        if ($node instanceof Coalesce) {
            return $this->isBladeVariable($node->left);
        }

        if ($node instanceof Expr\AssignOp\Coalesce) {
            return $this->isBladeVariable($node->var) || ($node->var instanceof Variable && $node->var->name === 'attributes' && $node->expr instanceof New_);
        }

        return false;
    }

    /** The loops `@props` and `@aware` run over the attributes, as `$__key => $__value`. */
    private function isBladeLoop(Foreach_ $node): bool
    {
        return $this->view && $node->keyVar instanceof Variable && $node->keyVar->name === '__key'
            && $node->valueVar instanceof Variable && $node->valueVar->name === '__value';
    }

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
