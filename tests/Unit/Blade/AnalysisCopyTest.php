<?php

declare(strict_types=1);

use Sonda\Report\Compat\CoverageApis;
use Sonda\Blade\AnalysisCopy;

/** @return array{functions: array<string, array{0: int, 1: int}>, executable: list<int>} what php-code-coverage makes of the copy */
function analysed(string $copy): array
{
    $path = TemporaryDirectory::create() . '/copy.php';
    file_put_contents($path, $copy);
    $analyser = CoverageApis::current()->analyser([]);
    $functions = [];

    if (onCoverage12()) {
        $result = $analyser->analyse($path);

        foreach ($result->functions() as $name => $function) {
            $functions[$name] = [$function->startLine(), $function->endLine()];
        }

        $executable = array_keys($result->executableLines());
    } else {
        foreach ($analyser->functionsIn($path) as $name => $function) {
            $functions[$name] = [$function['startLine'], $function['endLine']];
        }

        $executable = array_keys($analyser->executableLinesIn($path));
    }

    sort($executable);

    return ['functions' => $functions, 'executable' => $executable];
}

it('declares the whole view as the function view, on the same lines', function (): void {
    $compiled = "<h1>Home</h1>\n<?php if(\$show): ?>\n    <?php echo e(\$title); ?>\n\n<?php endif; ?>\n";

    $copy = AnalysisCopy::of($compiled, "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");

    expect($copy)->toBe("<?php function view() { ?><h1>Home</h1><?php ?>\n<?php if(\$show): ?>\n    <?php echo e(\$title); ?>\n<?php ?>\n<?php endif; ?><?php } ?>\n")
        ->and(substr_count($copy, "\n"))->toBe(substr_count($compiled, "\n"))
        ->and(analysed($copy))->toBe(['functions' => ['view' => [1, 5]], 'executable' => [1, 2, 3]]);
});

it('keeps an endif line out of the executable lines, which the newline of the HTML before it would drag in', function (): void {
    $compiled = "<?php if(\$loud): ?>\n    <strong>!</strong>\n<?php endif; ?>\n";

    expect(analysed($compiled)['executable'])->toBe([1, 2, 3])
        ->and(analysed(AnalysisCopy::of($compiled, $compiled))['executable'])->toBe([1, 2]);
});

it('blanks the use statements, which cannot sit in a function, keeping their lines', function (): void {
    $compiled = "<?php use App\\Models\\User; ?>\n<?php use function strlen,\n    strtoupper; use App\\{A, B}; echo 3; ?>\n<?php \$f = function () use (\$x) { return \$x; }; ?>\n<p><?php echo \$f(); ?></p>\n";
    $blank = static fn (string $statement): string => str_repeat(' ', strlen($statement));

    $copy = AnalysisCopy::of($compiled, $compiled);

    expect($copy)->toBe("<?php function view() { ?><?php " . $blank('use App\\Models\\User;') . " ?>\n<?php " . $blank('use function strlen,') . "\n" . $blank('    strtoupper; use App\\{A, B};') . " echo 3; ?>\n<?php \$f = function () use (\$x) { return \$x; }; ?>\n<p><?php echo \$f(); ?></p><?php ?><?php } ?>\n")
        ->and(analysed($copy)['functions'])->toBe(['view' => [1, 5]]);
});

it('blanks a use statement that a closing tag ends', function (): void {
    expect(AnalysisCopy::of("<?php use App\\Models\\User ?>\n<p>x</p>\n", ''))
        ->toBe("<?php function view() { ?><?php " . str_repeat(' ', strlen('use App\\Models\\User')) . " ?>\n<p>x</p><?php ?><?php } ?>\n");
});

it('blanks only the use statement, after braces of strings and blocks and before the code that follows it', function (): void {
    $compiled = "<?php echo \"{\$a} \${b}\"; foreach ([1] as \$i) { echo \$i; } use App\\A;echo 2; ?>\n";

    expect(AnalysisCopy::of($compiled, $compiled))
        ->toBe("<?php function view() { ?><?php echo \"{\$a} \${b}\"; foreach ([1] as \$i) { echo \$i; } " . str_repeat(' ', strlen('use App\\A;')) . "echo 2; ?><?php } ?>\n");
});

it('leaves the use of a trait alone, which sits inside a class', function (): void {
    $compiled = "<?php class Row { use Sortable; } ?>\n";

    expect(AnalysisCopy::of($compiled, $compiled))->toBe("<?php function view() { ?><?php class Row { use Sortable; } ?><?php } ?>\n");
});

it('gives back the newline the function opening eats when the view starts with one', function (): void {
    expect(AnalysisCopy::of("\n<p>x</p>\n", ''))->toBe("<?php function view() { ?>\n\n<p>x</p><?php ?><?php } ?>\n")
        ->and(AnalysisCopy::of("\r\n<p>x</p>\r\n", ''))->toBe("<?php function view() { ?>\n\r\n<p>x</p><?php ?><?php } ?>\r\n")
        ->and(AnalysisCopy::of("\r\n<p>x</p>", ''))->toBe("<?php function view() { ?>\n\r\n<p>x</p><?php } ?>")
        ->and(AnalysisCopy::of("<p>x</p>\r\n", ''))->toBe("<?php function view() { ?><p>x</p><?php ?><?php } ?>\r\n")
        ->and(AnalysisCopy::of("<p>x</p>", ''))->toBe("<?php function view() { ?><p>x</p><?php } ?>");
});

it('closes the function in PHP mode when the view ends in it', function (): void {
    expect(AnalysisCopy::of("<p>x</p>\n<?php echo 1;", ''))->toBe("<?php function view() { ?><p>x</p><?php ?>\n<?php echo 1; }")
        ->and(AnalysisCopy::of("<p>x</p>\n<?php echo 1;\n", ''))->toBe("<?php function view() { ?><p>x</p><?php ?>\n<?php echo 1; }\n");
});

it('pads the copy to the lines of the template, so the function spans them all', function (): void {
    $copy = AnalysisCopy::of("<p>x</p>\n", "<p>x</p>\n{{-- a comment\nover\nlines --}}\n");

    expect($copy)->toBe("<?php function view() { ?><p>x</p><?php ?>\n\n\n<?php } ?>\n")
        ->and(analysed($copy)['functions'])->toBe(['view' => [1, 4]])
        ->and(AnalysisCopy::of("<p>x</p>\n", "<p>x</p>\n{{-- c --}}\n"))->toBe("<?php function view() { ?><p>x</p><?php ?>\n<?php } ?>\n");
});
