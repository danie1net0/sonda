<?php

declare(strict_types=1);

namespace Sonda\Project;

use PhpParser\Node\{Expr, Name};
use PhpParser\Node\Scalar\{Int_, String_};
use PhpParser\Node\Expr\{BinaryOp\Concat, FuncCall, Include_};
use PhpParser\Node\Scalar\MagicConst\{Dir, File};
use PhpParser\{NodeFinder, ParserFactory};
use Sonda\{Filesystem, Scope};

use function dirname, str_contains, str_starts_with;

/**
 * The files of the scope that other files of the scope `require` or `include` directly. Only the
 * autoloader instruments: such a file runs from its original, uninstrumented, and shows no coverage.
 */
final class DirectRequires
{
    /**
     * @param list<string> $sourceFiles PHP files of the scope, as real paths
     *
     * @return list<DirectRequire> in the order of the files, then of the lines
     */
    public static function in(array $sourceFiles, Scope $scope): array
    {
        $parser = new ParserFactory()->createForHostVersion();
        $finder = new NodeFinder();
        $found = [];

        foreach ($sourceFiles as $file) {
            $code = Filesystem::read($file);

            if (! str_contains($code, 'require') && ! str_contains($code, 'include')) {
                continue;
            }

            foreach ($finder->findInstanceOf($parser->parse($code) ?? [], Include_::class) as $include) {
                $target = self::target($include->expr, $file);

                if ($target !== null && $target !== $file && $scope->includes($target)) {
                    $found[] = new DirectRequire($file, $include->getStartLine(), $target);
                }
            }
        }

        return $found;
    }

    /** The real path of an include whose argument is built from literals, `__DIR__`, `__FILE__` and `dirname()`. */
    private static function target(Expr $expression, string $file): ?string
    {
        $path = self::resolve($expression, $file);

        if ($path === null) {
            return null;
        }

        $real = realpath(str_starts_with($path, '/') ? $path : dirname($file) . '/' . $path);

        return $real === false || ! is_file($real) ? null : $real;
    }

    private static function resolve(Expr $expression, string $file): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof Dir) {
            return dirname($file);
        }

        if ($expression instanceof File) {
            return $file;
        }

        if ($expression instanceof Concat) {
            $left = self::resolve($expression->left, $file);
            $right = self::resolve($expression->right, $file);

            return $left === null || $right === null ? null : $left . $right;
        }

        if ($expression instanceof FuncCall) {
            return $expression->name instanceof Name && $expression->name->toLowerString() === 'dirname' ? self::dirnameOf($expression, $file) : null;
        }

        return null;
    }

    private static function dirnameOf(FuncCall $call, string $file): ?string
    {
        $arguments = $call->getArgs();
        $path = isset($arguments[0]) ? self::resolve($arguments[0]->value, $file) : null;

        if ($path === null) {
            return null;
        }

        if (! isset($arguments[1])) {
            return dirname($path);
        }

        $levels = $arguments[1]->value;

        if (! $levels instanceof Int_ || $levels->value < 1) {
            return null;
        }

        return dirname($path, $levels->value);
    }
}
