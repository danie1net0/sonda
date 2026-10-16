<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\Blade\LineMap;

function bladeCompiler(): BladeCompiler
{
    $compiler = new BladeCompiler(new Filesystem(), TemporaryDirectory::create());
    // A directive that compiles to three lines of PHP, as a component tag does.
    $compiler->directive('spread', static fn (): string => "<?php\n\$spread = 1;\n\$spread++;\n?>");

    return $compiler;
}

/** @return array<int, int> template line of each compiled line that contains the text */
function linesContaining(string $compiled, array $map, string $text): array
{
    $lines = [];

    foreach (explode("\n", $compiled) as $index => $line) {
        if (str_contains($line, $text)) {
            $lines[$index + 1] = $map[$index + 1];
        }
    }

    return $lines;
}

it('maps each compiled line to the template line of the construct it comes from', function (): void {
    $compiler = bladeCompiler();
    $template = "<div>\n@if (\$show)\n    {{ \$title }}\n@endif\n@spread\n<p>after</p>\n{{-- a comment\nover two lines --}}\n@foreach (\$items as \$item)\n    <li>{{ \$item }}</li>\n@endforeach\n";
    $compiled = $compiler->compileString($template);

    $map = LineMap::of($template, $compiled, static fn (string $template): string => $compiler->compileString($template));

    expect(count($map))->toBe(count(explode("\n", $compiled)))
        ->and(array_values(linesContaining($compiled, $map, '<div>')))->toBe([1])
        ->and(array_values(linesContaining($compiled, $map, 'if($show)')))->toBe([2])
        ->and(array_values(linesContaining($compiled, $map, 'echo e($title)')))->toBe([3])
        ->and(array_values(linesContaining($compiled, $map, 'endif')))->toBe([4])
        ->and(array_values(linesContaining($compiled, $map, '$spread')))->toBe([5, 5])
        ->and(array_values(linesContaining($compiled, $map, '<p>after</p>')))->toBe([6])
        ->and(array_values(linesContaining($compiled, $map, 'foreach($__currentLoopData')))->toBe([9])
        ->and(array_values(linesContaining($compiled, $map, '<li>')))->toBe([10])
        ->and(array_values(linesContaining($compiled, $map, 'endforeach')))->toBe([11]);
});

it('keeps the lines of a php block, which compile one to one', function (): void {
    $compiler = bladeCompiler();
    $template = "@php\n    \$a = 1;\n    \$b = 2;\n@endphp\n<span>{{ \$a + \$b }}</span>\n";
    $compiled = $compiler->compileString($template);

    $map = LineMap::of($template, $compiled, static fn (string $template): string => $compiler->compileString($template));

    expect(array_values(linesContaining($compiled, $map, '$a = 1;')))->toBe([2])
        ->and(array_values(linesContaining($compiled, $map, '$b = 2;')))->toBe([3])
        ->and(array_values(linesContaining($compiled, $map, 'echo e($a + $b)')))->toBe([5]);
});

it('leaves a template without constructs alone and maps its lines by their text', function (): void {
    $compile = static fn (string $template): string => $template;

    expect(LineMap::of("<p>\n<p>\n\n<p>\n", "<p>\n<p>\n\n<p>\n", $compile))->toBe([1 => 1, 2 => 2, 3 => 2, 4 => 4, 5 => 4]);
});

it('looks for the text of an unmarked compiled line from the line of the last construct on, not before', function (): void {
    // Line 3 repeats line 1: after the directive on line 2, the compiled "<p>" is line 3.
    $compile = static fn (string $template): string => str_replace('@a', '<?php a(); ?>', $template);

    expect(LineMap::of("<p>\n@a\n<p>\n", "<p>\n<?php a(); ?>\n<p>\n", $compile))->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 3]);
});

it('falls back to the compiled line numbers when the marked template compiles to another number of lines', function (): void {
    $compiled = "<?php a(); ?>\n<?php b(); ?>\n";

    $map = LineMap::of("@a\n@b\n", $compiled, static fn (string $template): string => str_contains($template, '|---SONDA:') ? "<?php a(); ?>\n" : $compiled);

    expect($map)->toBe([1 => 1, 2 => 2, 3 => 3]);
});

it('falls back to the compiled line numbers when the marked template does not compile', function (): void {
    $map = LineMap::of("@a\n", "<?php a(); ?>\n", static function (string $template): string {
        if (str_contains($template, '|---SONDA:')) {
            throw new RuntimeException('marked');
        }

        return "<?php a(); ?>\n";
    });

    expect($map)->toBe([1 => 1, 2 => 2]);
});

it('marks a template once per construct, including escaped echos, raw echos and component tags', function (): void {
    $compile = static fn (string $template): string => $template;
    $template = "@{{ a }} {!! b !!} <x-alert /> </x-alert> <livewire:counter />\n@if(1)@endif";

    $map = LineMap::of($template, $template, $compile);

    expect($map)->toBe([1 => 1, 2 => 2]);
    expect(LineMap::of("plain\n@if (\n    \$a\n)\nx\n@endif\n", "plain\n<?php if(\n    \$a\n): ?>\nx\n<?php endif; ?>\n", static fn (string $t): string => str_replace(['@if (', '@endif'], ['<?php if(', '<?php endif; ?>'], str_replace(")\nx", "): ?>\nx", $t))))
        ->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 5, 6 => 6, 7 => 6]);
});
