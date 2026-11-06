<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Sonda\Blade\LineMap;

/** A class component for the component tags of the templates. */
final class LineMapCard extends Component
{
    public function __construct(public string $title = '')
    {
    }

    public function render(): string
    {
        return '';
    }
}

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

/** A compiler of `@a`, `@b` and `@c`, one line each, but `@b` compiles to two lines, as the marked template gives them. */
function splitCompiler(string $markedB): Closure
{
    return static fn (string $template): string => str_replace(
        ['@a', '@b', '@c'],
        ['<?php a(); ?>', str_contains($template, '|---SONDA:') ? $markedB : "<?php\nb(); ?>", '<?php c(); ?>'],
        $template,
    );
}

it('lines up the compilations by text, so a construct the markers change moves no line around it', function (): void {
    // Marked, `@b` compiles to one line: its two compiled lines take no marker, the lines around it keep theirs.
    $template = "@a\n@b\n@c\n";
    $compile = splitCompiler('<?php b(); ?>');

    $map = LineMap::of($template, $compile($template), $compile);

    expect($map)->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 3, 5 => 3]);
});

it('lines up changed lines one by one when the markers keep their number', function (): void {
    // Marked, the first line of `@b` reads otherwise: it still takes the marker of line 2.
    $template = "@a\n@b\n@c\n";
    $compile = splitCompiler("<?php /* marked */\nb(); ?>");

    $map = LineMap::of($template, $compile($template), $compile);

    expect($map)->toBe([1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3]);
});

it('lines up the compilations line by line when they differ in more lines than it compares', function (): void {
    $template = str_repeat("@a\n", 300);
    $compile = static fn (string $template): string => str_contains($template, '|---SONDA:') ? str_replace('@a', 'marked', $template) : str_replace('@a', 'plain', $template);

    $map = LineMap::of($template, $compile($template), $compile);

    // 300 lines only in each compilation: past the edits it compares, the same number of lines still pair one by one.
    expect(array_values($map))->toBe([...range(1, 300), 300]);
});

it('maps the compiled lines by their text alone when the marked template does not compile', function (): void {
    $map = LineMap::of("<p>\n@a\n<p>\n", "<p>\n<?php a(); ?>\n<p>\n", static function (string $template): string {
        if (str_contains($template, '|---SONDA:')) {
            throw new RuntimeException('marked');
        }

        return "<p>\n<?php a(); ?>\n<p>\n";
    });

    expect($map)->toBe([1 => 1, 2 => 1, 3 => 3, 4 => 3]);
});

it('marks a template once per construct, including escaped echos, raw echos and component tags', function (): void {
    $compile = static fn (string $template): string => $template;
    $template = "@{{ a }} {!! b !!} <x-alert /> </x-alert> <livewire:counter />\n@if(1)@endif";

    $map = LineMap::of($template, $template, $compile);

    expect($map)->toBe([1 => 1, 2 => 2]);
    expect(LineMap::of("plain\n@if (\n    \$a\n)\nx\n@endif\n", "plain\n<?php if(\n    \$a\n): ?>\nx\n<?php endif; ?>\n", static fn (string $template): string => str_replace(['@if (', '@endif'], ['<?php if(', '<?php endif; ?>'], str_replace(")\nx", "): ?>\nx", $template))))
        ->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 5, 6 => 6, 7 => 6]);
});

it('marks a component tag once, at its start, so its attributes still compile and no line passes the template', function (): void {
    Container::getInstance()->instance(Factory::class, test()->createStub(Factory::class));
    $compiler = bladeCompiler();
    $compiler->component(LineMapCard::class, 'card');

    $template = "@if (\$show)\n  <x-card\n    {{ \$attributes }}\n    @class(['wide' => \$size > 1])\n    :title=\"\$a > \$b ? 'yes' : 'no'\"\n    data-label='{{ \$label }}'\n  />\n@endif\n<p>{{ \$after }}</p>\n";
    $compiled = $compiler->compileString($template);

    $map = LineMap::of($template, $compiled, static fn (string $template): string => $compiler->compileString($template));

    expect(count($map))->toBe(count(explode("\n", $compiled)))
        ->and(max($map))->toBeLessThanOrEqual(substr_count($template, "\n") + 1)
        ->and(array_unique(array_values(linesContaining($compiled, $map, '$__componentOriginal'))))->toBe([2])
        ->and(array_values(linesContaining($compiled, $map, 'endif')))->toContain(8)
        ->and(array_values(linesContaining($compiled, $map, 'echo e($after)')))->toBe([9]);

    Container::setInstance();
});

it('keeps the markers inside a custom HTML element, which Blade compiles as text', function (): void {
    $compiler = bladeCompiler();
    $template = "<my-button\n  label=\"{{ \$label }}\"\n>\n</my-button>\n";
    $compiled = $compiler->compileString($template);

    $map = LineMap::of($template, $compiled, static fn (string $template): string => $compiler->compileString($template));

    expect(array_values(linesContaining($compiled, $map, 'echo e($label)')))->toBe([2]);
});

it('ends a component tag at its own closing bracket, past quotes, echos and parentheses that hold one', function (): void {
    $compile = static fn (string $template): string => $template;
    $template = "<x-a b=\"1 > 0\" c='>' {{ \$d->e }} {!! \$f->g !!} @h(\$i > (1)) { ( >\n{{ \$after }}\n";

    $markers = [];
    preg_match_all('/\\|---SONDA:(\\d+)---\\|/', new ReflectionMethod(LineMap::class, 'mark')->invoke(null, $template), $markers);

    expect($markers[1])->toBe(['1', '2'])
        ->and(LineMap::of($template, $template, $compile))->toBe([1 => 1, 2 => 2, 3 => 2]);
});

it('ends an unclosed component tag at the end of the template', function (): void {
    $template = "<x-a\n{{ \$b }}";

    $markers = [];
    preg_match_all('/\\|---SONDA:(\\d+)---\\|/', new ReflectionMethod(LineMap::class, 'mark')->invoke(null, $template), $markers);

    expect($markers[1])->toBe(['1']);
});
