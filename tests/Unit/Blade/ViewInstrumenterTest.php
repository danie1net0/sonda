<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\Blade\ViewInstrumenter;
use Sonda\{Hits, Instrumenter};

it('instruments the compiled code of a view and keeps the way back to the template', function (): void {
    $root = TemporaryDirectory::create();
    $view = $root . '/home.blade.php';
    file_put_contents($view, "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");
    $compiler = new BladeCompiler(new Filesystem(), $root . '/compiled');
    $compile = static fn (string $template): string => $compiler->compileString($template);

    $result = new ViewInstrumenter(new Instrumenter())->instrument($view, $root . '/compiled/home.php', 'vid', $compile);

    expect($result->analysisCode)->toBe($compile((string) file_get_contents($view)))
        ->and($result->code)->toContain('\\' . Hits::class . "::\$current['vid']")
        ->and($result->probes)->not->toBe([])
        ->and($result->lineMap)->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 4, 6 => 4]);
});
