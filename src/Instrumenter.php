<?php declare(strict_types=1);

namespace CovProto;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * Source-level instrumenter: inserts probe text at byte offsets of the
 * original source, so line numbers never move.
 *
 * Probe meta: id => [line|null, isBranch, functionKey, lineStart, lineEnd]
 */
final class Instrumenter
{
    public function __construct(private bool $strict = false, private bool $fast = false)
    {
    }

    /** @return array{code: string, probes: array<int, array{0: ?int, 1: bool, 2: string, 3: int, 4: int}>} */
    public function instrument(string $path, string $fid): array
    {
        $code   = file_get_contents($path);
        $parser = (new ParserFactory)->createForHostVersion();
        $ast    = $parser->parse($code);
        $tokens = $parser->getTokens();

        $visitor = new InstrumentingVisitor($tokens, $fid, $path, $this->strict, $this->fast);
        $visitor->rootStmts($ast);

        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($ast);

        return ['code' => self::apply($code, $visitor->edits), 'probes' => $visitor->probes];
    }

    /** @param list<array{0:int,1:int,2:int,3:string,4:int}> $edits [pos, kind(0=close,1=open), order, text, deleteLen] */
    private static function apply(string $code, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $out = '';
        $cur = 0;

        foreach ($edits as [$pos, , , $text, $delete]) {
            if ($pos < $cur) {
                continue; // inside a replaced range
            }
            $out .= substr($code, $cur, $pos - $cur) . $text;
            $cur  = $pos + $delete;
        }

        return $out . substr($code, $cur);
    }
}

final class InstrumentingVisitor extends NodeVisitorAbstract
{
    /** @var list<array{0:int,1:int,2:int,3:string,4:int}> */
    public array $edits = [];

    /** @var array<int, array{0: ?int, 1: bool, 2: string, 3: int, 4: int}> */
    public array $probes = [];

    private int $next = 0;

    /** @var array<int, true> */
    private array $probedLines = [];
    private int $depth = 0;
    private string $namespace = '';

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<string> */
    private array $fnStack = ['{main}'];

    /** @param Token[] $tokens */
    /** @var list<bool> whether the current function holds a local $__cov reference */
    private array $localStack = [false];

    public function __construct(private array $tokens, private string $fid, private string $path, private bool $strict, private bool $fast = false)
    {
    }

    /** fast mode: bind a local reference once per call, so each probe is a single ASSIGN_DIM */
    private function enterFunction(?array $stmts): void
    {
        $local = $this->fast && $stmts !== null && $stmts !== [];
        $this->localStack[] = $local;
        if ($local) {
            $this->edit($stmts[0]->getStartFilePos(), 1, PHP_INT_MIN, '$__cov = &\\CovProto\\R::$h[\'' . $this->fid . '\'];');
        }
    }

    /** @param Node[] $ast */
    public function rootStmts(array $ast): void
    {
        $this->stmtList($ast, false, true);
    }

    public function enterNode(Node $node)
    {
        $this->depth++;

        if ($node instanceof Node\Param || $node instanceof Node\PropertyItem || $node instanceof Node\Const_
            || $node instanceof Node\AttributeGroup || $node instanceof Stmt\EnumCase
            || $node instanceof Node\StaticVar || $node instanceof Stmt\Declare_) {
            // constant-expression contexts (and declare) are left untouched
            if ($node instanceof Stmt\Declare_ && $node->stmts) {
                $this->stmtList($node->stmts, false, false);
                return null;
            }

            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name ? $node->name->toString() . '\\' : '';
            $this->stmtList($node->stmts, false, true);
        } elseif ($node instanceof Stmt\ClassLike) {
            $this->classStack[] = $node->name ? $this->namespace . $node->name->toString() : 'class@anonymous';
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->fnStack[] = end($this->classStack) . '->' . $node->name->toString();
            $this->enterFunction($node->stmts);
            if ($node->stmts !== null) {
                $node->stmts === [] ? $this->emptyFunctionBody($node) : $this->stmtList($node->stmts, true, false);
            }
        } elseif ($node instanceof Stmt\Function_) {
            $this->fnStack[] = $this->namespace . $node->name->toString();
            $this->enterFunction($node->stmts);
            $node->stmts === [] ? $this->emptyFunctionBody($node) : $this->stmtList($node->stmts, true, false);
        } elseif ($node instanceof Expr\Closure) {
            $this->fnStack[] = '{closure}';
            $this->enterFunction($node->stmts);
            $node->stmts === [] ? $this->emptyFunctionBody($node) : $this->stmtList($node->stmts, true, false);
        } elseif ($node instanceof Expr\ArrowFunction) {
            $this->fnStack[] = '{closure}';
            $this->localStack[] = false;
            if (!$node->byRef) {
                $this->wrapExpr($node->expr, true);
                $this->probes[$this->next - 1][5] = $this->ownLines($node->expr);
            }
        } elseif ($node instanceof Node\PropertyHook) {
            $this->fnStack[] = '{hook}';
            $this->localStack[] = false;
            if (is_array($node->body)) {
                $this->stmtList($node->body, true, false);
            }
        } elseif ($node instanceof Stmt\If_) {
            $this->body($node, $node->stmts, true);
            if ($this->strict && $node->else === null) {
                $this->syntheticElse($node);
            }
        } elseif ($node instanceof Stmt\ElseIf_) {
            $this->wrapExpr($node->cond, false);
            $this->body($node, $node->stmts, true);
        } elseif ($node instanceof Stmt\Do_) {
            $this->wrapExpr($node->cond, false);
            $this->body($node, $node->stmts, true);
        } elseif ($node instanceof Stmt\Else_
            || $node instanceof Stmt\While_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
            ) {
            $this->body($node, $node->stmts, true);
        } elseif ($node instanceof Stmt\Case_) {
            if ($node->cond !== null && !self::isConstant($node->cond)) {
                $this->wrapExpr($node->cond, false);
            }
            $this->stmtList($node->stmts, true, false);
        } elseif ($node instanceof Stmt\TryCatch) {
            $this->stmtList($node->stmts, false, false);
        } elseif ($node instanceof Stmt\Catch_ || $node instanceof Stmt\Finally_) {
            $this->stmtList($node->stmts, true, false);
            if ($node instanceof Stmt\Catch_) {
                $real = array_values(array_filter($node->stmts, static fn (Node $n): bool => !$n instanceof Stmt\Nop));
                $id   = $this->probe($node->getStartLine(), $real === [], $node->getStartLine(), $node->getEndLine());
                $pos  = $real !== [] ? $real[0]->getStartFilePos() : $node->getEndFilePos();
                $this->edit($pos, 1, $this->depth + 1, $this->probeCode($id) . ';');
            }
        } elseif ($node instanceof Stmt\Block) {
            $this->stmtList($node->stmts, false, false);
        } elseif ($node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd || $node instanceof Expr\BinaryOp\LogicalOr
            || $node instanceof Expr\BinaryOp\Coalesce) {
            if (!isset($this->probedLines[$node->left->getStartLine()]) && !self::isLogical($node->left)) {
                $this->wrapExpr($node->left, false);
            }
            $this->wrapExpr($node->right, true);
        } elseif ($node instanceof Expr\AssignOp\Coalesce) {
            $this->wrapExpr($node->expr, true);
        } elseif ($node instanceof Expr\Ternary) {
            if ($node->if !== null) {
                $this->wrapExpr($node->if, true);
            }
            $this->wrapExpr($node->else, true);
        } elseif ($node instanceof Expr\Match_) {
            foreach ($node->arms as $arm) {
                foreach ($arm->conds ?? [] as $cond) {
                    if (!$cond instanceof Node\Scalar) {
                        $this->wrapExpr($cond, false);
                    }
                }
                $this->wrapExpr($arm->body, true);
            }
        } elseif ($node instanceof Node\Scalar\MagicConst\File) {
            $this->replace($node, var_export($this->path, true));
        } elseif ($node instanceof Node\Scalar\MagicConst\Dir) {
            $this->replace($node, var_export(dirname($this->path), true));
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        $this->depth--;

        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Node\PropertyHook) {
            array_pop($this->fnStack);
            array_pop($this->localStack);
        }

        return null;
    }

    /** Body of if/elseif/else/loops: may be unbraced (then wrap in braces). */
    private function body(Node $owner, array $stmts, bool $outcome): void
    {
        if ($stmts === []) {
            return; // empty body: not instrumented in the prototype
        }

        $first = $stmts[0];
        $prev  = $this->prevSignificantToken($first->getStartTokenPos());
        $braced = $prev !== null && ($prev->text === '{' || $prev->text === ':');

        if (!$braced) {
            $last = end($stmts);
            $this->edit($first->getStartFilePos(), 1, $this->depth, '{ ');
            $this->edit($last->getEndFilePos() + 1, 0, -$this->depth, ' }');
        }

        $this->stmtList($stmts, $outcome, false);
    }

    private function emptyFunctionBody(Node $fn): void
    {
        if ($this->tokens[$fn->getEndTokenPos()]->text !== '}') {
            return;
        }
        $id = $this->probe($fn->getEndLine(), true, $fn->getEndLine(), $fn->getEndLine());
        $this->edit($fn->getEndFilePos(), 1, $this->depth + 1, $this->probeCode($id) . ';');
    }

    /** @return list<int> lines of an expression, minus lines of nested functions */
    private function ownLines(Expr $expr): array
    {
        $lines = range($expr->getStartLine(), $expr->getEndLine());
        $finder = new \PhpParser\NodeFinder;
        foreach ($finder->find($expr, static fn (Node $n): bool => $n instanceof Expr\Closure || $n instanceof Expr\ArrowFunction) as $fn) {
            $lines = array_diff($lines, range($fn->getStartLine() + 1, $fn->getEndLine()));
        }

        return array_values($lines);
    }

    private static function isConstant(Expr $e): bool
    {
        return $e instanceof Expr\ClassConstFetch || $e instanceof Expr\ConstFetch;
    }

    private static function isLogical(Expr $e): bool
    {
        return $e instanceof Expr\BinaryOp\BooleanAnd || $e instanceof Expr\BinaryOp\BooleanOr
            || $e instanceof Expr\BinaryOp\LogicalAnd || $e instanceof Expr\BinaryOp\LogicalOr
            || $e instanceof Expr\BinaryOp\Coalesce;
    }

    /** @param Node[] $stmts */
    private function stmtList(array $stmts, bool $outcome, bool $topLevel): void
    {
        $firstProbe = true;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Nop || $stmt instanceof Stmt\Namespace_ || $stmt instanceof Stmt\Declare_
                || $stmt instanceof Stmt\HaltCompiler || $stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse
                || $stmt instanceof Stmt\ClassLike || $stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\Const_
                || $stmt instanceof Stmt\InlineHTML) {
                continue;
            }

            $isBranch = $outcome && $firstProbe;
            $last     = end($stmts);
            $id       = $this->probe(
                $stmt->getStartLine(),
                $isBranch,
                $isBranch ? $stmt->getStartLine() : 0,
                $isBranch ? $last->getEndLine() : 0,
            );
            $firstProbe = false;

            $this->edit($stmt->getStartFilePos(), 1, $this->depth + 1, $this->probeCode($id) . ';');
        }
    }

    private function wrapExpr(Expr $expr, bool $branch): void
    {
        $id = $this->probe($expr->getStartLine(), $branch, $expr->getStartLine(), $expr->getEndLine());

        $this->edit($expr->getStartFilePos(), 1, $this->depth + 1, '((' . $this->probeCode($id) . ') ? (');
        $this->edit($expr->getEndFilePos() + 1, 0, -($this->depth + 1), ') : null)');
    }

    private function syntheticElse(Stmt\If_ $node): void
    {
        $last = $node->elseifs !== [] ? end($node->elseifs) : $node;
        $end  = $this->tokens[$node->getEndTokenPos()]->text;

        if ($end !== '}') {
            return; // alternative syntax / unbraced: skipped in the prototype
        }

        $id = $this->probe(null, true, $node->getEndLine(), $node->getEndLine());
        $this->edit($node->getEndFilePos() + 1, 0, -$this->depth, ' else { ' . $this->probeCode($id) . '; }');
    }

    private function replace(Node $node, string $text): void
    {
        $start = $node->getStartFilePos();
        $this->edit($start, 1, PHP_INT_MAX, $text, $node->getEndFilePos() + 1 - $start);
    }

    private function probe(?int $line, bool $branch, int $ls, int $le): int
    {
        $id = $this->next++;
        if ($line !== null) {
            $this->probedLines[$line] = true;
        }
        $this->probes[$id] = [$line, $branch, end($this->fnStack), $ls, $le];

        return $id;
    }

    private function probeCode(int $id): string
    {
        if (end($this->localStack)) {
            return '$__cov[' . $id . ']=1';
        }

        return '\CovProto\R::$h[\'' . $this->fid . '\'][' . $id . ']=1';
    }

    private function edit(int $pos, int $kind, int $order, string $text, int $delete = 0): void
    {
        $this->edits[] = [$pos, $kind, $order, $text, $delete];
    }

    private function prevSignificantToken(int $pos): ?Token
    {
        for ($i = $pos - 1; $i >= 0; $i--) {
            if (!$this->tokens[$i]->isIgnorable()) {
                return $this->tokens[$i];
            }
        }

        return null;
    }
}
