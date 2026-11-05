<?php

declare(strict_types=1);

use PhpParser\{Node, NodeFinder, ParserFactory};
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\{ElseIf_, Else_, If_, Return_, Use_};

/** @return Generator<string, array{string}> */
function codingStandardFiles(): Generator
{
    $root = dirname(__DIR__, 2);

    foreach (['src', 'tests/Unit', 'tests/Feature', 'tests/Support', 'tools'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $arguments = [];
            $arguments[] = $file->getPathname();

            yield substr($file->getPathname(), strlen($root) + 1) => $arguments;
        }
    }

    foreach (['autoload.php', 'bootstrap.php', 'report.php', 'bin/sonda'] as $entry) {
        $arguments = [];
        $arguments[] = $root . '/' . $entry;

        yield $entry => $arguments;
    }
}

/** @return list<string> */
function controlFlowViolations(Node $node, int $depth = 0): array
{
    $depth = $node instanceof FunctionLike ? 0 : $depth;
    $violations = [];

    if ($node instanceof Else_ || $node instanceof ElseIf_) {
        $violations[] = 'else/elseif on line ' . $node->getStartLine();
    }

    if ($node instanceof If_ && $depth > 0) {
        $violations[] = 'nested if on line ' . $node->getStartLine();
    }

    $depth += (int) ($node instanceof If_);

    foreach ($node->getSubNodeNames() as $name) {
        $children = is_array($node->$name) ? $node->$name : [$node->$name];

        foreach ($children as $child) {
            if (! $child instanceof Node) {
                continue;
            }

            foreach (controlFlowViolations($child, $depth) as $violation) {
                $violations[] = $violation;
            }
        }
    }

    return $violations;
}

it('keeps executable code flat, leaving syntax samples in fixture strings alone', function (string $file): void {
    $nodes = new ParserFactory()->createForHostVersion()->parse(file_get_contents($file));

    foreach ($nodes as $node) {
        expect(controlFlowViolations($node))->toBe([], $file);
    }
})->with(codingStandardFiles());

it('composes returned arrays by assignment', function (string $file): void {
    $nodes = new ParserFactory()->createForHostVersion()->parse(file_get_contents($file));
    $returns = new NodeFinder()->findInstanceOf($nodes, Return_::class);
    $violations = [];

    foreach ($returns as $return) {
        // Configuration files may return literals.
        if ($return->expr instanceof Array_ && $return->expr->items !== [] && basename($file) !== 'phpstan-for-installed-coverage.php') {
            $violations[] = $return->getStartLine();
        }
    }

    expect($violations)->toBe([], $file);
})->with(codingStandardFiles());

it('imports only frameless native functions and never constants', function (string $file): void {
    $allowed = ['in_array', 'implode', 'min', 'max', 'is_numeric', 'class_exists', 'dirname', 'str_contains', 'str_starts_with', 'str_replace', 'strpos', 'substr', 'trim', 'strtr', 'preg_match', 'preg_replace', 'property_exists'];
    $nodes = new ParserFactory()->createForHostVersion()->parse(file_get_contents($file));
    $violations = [];

    foreach (new NodeFinder()->findInstanceOf($nodes, Use_::class) as $use) {
        if ($use->type === Use_::TYPE_CONSTANT) {
            $violations[] = 'constant import on line ' . $use->getStartLine();
        }

        if ($use->type !== Use_::TYPE_FUNCTION) {
            continue;
        }

        foreach ($use->uses as $import) {
            if (! in_array($import->name->toString(), $allowed, true)) {
                $violations[] = 'function import on line ' . $import->getStartLine();
            }
        }
    }

    expect($violations)->toBe([], $file);
})->with(codingStandardFiles());

it('keeps a dedicated test file for each source class', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root . '/src/'), -4);

        expect(is_file($root . '/tests/Unit/' . $relative . 'Test.php'))->toBeTrue($relative);
    }
});
