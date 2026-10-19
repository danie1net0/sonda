<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Node\{AbstractNode, File};
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\Blade\ViewInstrumenter;
use Sonda\{InstrumentationCache, InstrumentationResult, Instrumenter, Probe, Run};
use Sonda\Report\{CoverageBuilder, Uncovered};
use SebastianBergmann\CodeCoverage\Data\ProcessedBranchCoverageData;

it('builds a coverage object with the branch data of the run and the source files never loaded', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    $order = $root . '/app/Order.php';
    $unused = $root . '/app/Unused.php';
    file_put_contents($order, "<?php\n\nfinal class Order\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");
    file_put_contents($unused, "<?php\n\nfunction unused(): int\n{\n    return 1;\n}\n");
    new InstrumentationCache($root . '/cache')->store('key', new InstrumentationResult('<?php', [0 => new Probe(7, true, 'Order->total', 7, 7)]));
    $run = new Run($root . '/cache', ['abc' => [$order, 'key']], ['abc' => [0 => 1]]);

    $coverage = CoverageBuilder::build($run, [$order, $unused]);
    $report = $coverage->getReport();

    expect($coverage->collectsBranchAndPathCoverage())->toBeTrue()
        ->and(array_keys($coverage->getData()->lineCoverage()))->toEqualCanonicalizing([$order, $unused])
        ->and($report->numberOfExecutableLines())->toBe(2)
        ->and($report->numberOfExecutedLines())->toBe(1)
        ->and($report->numberOfExecutableBranches())->toBe(1)
        ->and($report->numberOfExecutedBranches())->toBe(1);
});

it('reports a view on the lines of its template, from the coverage of its compiled code', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/resources/views', 0777, true);
    $view = $root . '/resources/views/home.blade.php';
    file_put_contents($view, "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");
    // Five compiled lines, out of template order: lines 1 and 2 stand for template line 4 (both hit, by the
    // same test), 3 and 4 for template line 2 (only the first hit), and 5 for template line 1 (not hit).
    $compiled = "<?php \$a = 1; ?>\n<?php \$b = 2; ?>\n<?php \$c = 3; ?>\n<?php \$d = 4; ?>\n<?php \$e = 5; ?>\n";
    $probes = [];

    foreach ([1, 2, 3, 4, 5] as $line) {
        $probes[] = new Probe($line, false, '{main}', 0, 0);
    }

    new InstrumentationCache($root . '/cache')->store('view', new InstrumentationResult($compiled, $probes, [1 => 4, 2 => 4, 3 => 2, 4 => 2, 5 => 1], $compiled));
    $run = new Run($root . '/cache', ['v' => [$view, 'view']], ['v' => [0 => 1, 1 => 1, 2 => 1]]);

    $coverage = CoverageBuilder::build($run, [$view]);
    $report = $coverage->getReport();

    expect($coverage->getData()->lineCoverage())->toBe([$view => [1 => [], 2 => ['sonda'], 4 => ['sonda']]])
        ->and($coverage->getData()->functionCoverage())->toBe([$view => []])
        ->and($coverage->filter()->files())->toBe([$view])
        ->and($coverage->getTests())->toHaveKey('sonda')
        ->and($report->numberOfExecutableLines())->toBe(3)
        ->and($report->numberOfExecutedLines())->toBe(2);
});

it('reports the branches of a view on the lines of its template, as the function view the report counts', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/resources/views', 0777, true);
    $view = $root . '/resources/views/home.blade.php';
    file_put_contents($view, "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");
    $compiler = new BladeCompiler(new Filesystem(), $root . '/compiled');
    $result = new ViewInstrumenter(new Instrumenter(strict: true))->instrument($view, $root . '/compiled/home.php', 'v', static fn (string $template): string => $compiler->compileString($template));
    new InstrumentationCache($root . '/cache')->store('view', $result);
    // Every probe hit but those of the @if body, compiled lines 3 and 4; the synthetic else has no line.
    $hits = [];

    foreach ($result->probes as $id => $probe) {
        if ($probe->line === null || $probe->line < 3) {
            $hits[$id] = 1;
        }
    }

    $coverage = CoverageBuilder::build(new Run($root . '/cache', ['v' => [$view, 'view']], ['v' => $hits]), [$view]);
    $report = $coverage->getReport();
    $file = array_values(array_filter(iterator_to_array($report, false), static fn (AbstractNode $node): bool => $node instanceof File))[0];
    $branches = $coverage->getData()->functionCoverage()[$view][ViewInstrumenter::FUNCTION]->branches;

    expect(array_keys($coverage->getData()->functionCoverage()[$view]))->toBe([ViewInstrumenter::FUNCTION])
        ->and(array_map(static fn (ProcessedBranchCoverageData $branch): array => [$branch->line_start, $branch->line_end, $branch->hit !== []], $branches))->toBe([[3, 3, false], [4, 4, true]])
        ->and($file)->toBeInstanceOf(File::class)
        ->and(array_keys($file->functions()))->toBe([ViewInstrumenter::FUNCTION])
        ->and($file->functions()[ViewInstrumenter::FUNCTION]->startLine)->toBe(1)
        ->and($report->numberOfExecutableBranches())->toBe(2)
        ->and($report->numberOfExecutedBranches())->toBe(1)
        ->and($report->numberOfExecutableLines())->toBe(3)
        ->and($report->numberOfExecutedLines())->toBe(2)
        ->and(Uncovered::of($coverage))->toBe([$view => ['lines' => [3], 'branches' => [[3, 3]]]]);
});

it('keeps a view without run data out of the report, which cannot tell its executable lines', function (): void {
    $root = TemporaryDirectory::create();
    $view = $root . '/home.blade.php';
    $other = $root . '/Other.php';
    file_put_contents($view, "<h1>{{ \$title }}</h1>\n");
    file_put_contents($other, "<?php\n\nfunction other(): int\n{\n    return 1;\n}\n");
    new InstrumentationCache($root . '/cache')->store('other', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'other', 5, 5)]));
    $run = new Run($root . '/cache', ['o' => [$other, 'other']], ['o' => [0 => 1]]);

    $coverage = CoverageBuilder::build($run, [$other, $view]);

    expect(array_keys($coverage->getData()->lineCoverage()))->toBe([$other])
        ->and($coverage->getData()->lineCoverage()[$other][5])->toBe(['sonda'])
        ->and($coverage->filter()->files())->toBe([$other]);
});

it('maps the lines php-code-coverage marks as not executable, and keeps the source files beside the view', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/resources/views', 0777, true);
    $view = $root . '/resources/views/home.blade.php';
    $other = $root . '/Other.php';
    file_put_contents($view, "@if (\$a)\n    {{ \$b }}\n@endif\n");
    file_put_contents($other, "<?php\n\nfunction other(): int\n{\n    return 1;\n}\n");
    // Line 3 has no probe: php-code-coverage decides it from its branch, never executed.
    $compiled = "<?php if (\$a) {\n    \$b = 1;\n    \$c = 2;\n}\n\$d = 3;\n";
    new InstrumentationCache($root . '/cache')->store('view', new InstrumentationResult($compiled, [0 => new Probe(1, false, '{main}', 0, 0), 1 => new Probe(2, false, '{main}', 0, 0), 2 => new Probe(5, false, '{main}', 0, 0)], [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3], $compiled));
    new InstrumentationCache($root . '/cache')->store('other', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'other', 5, 5)]));
    $run = new Run($root . '/cache', ['v' => [$view, 'view'], 'o' => [$other, 'other']], ['v' => [0 => 1, 2 => 1], 'o' => [0 => 1]]);

    $coverage = CoverageBuilder::build($run, [$other, $view]);

    expect($coverage->getData()->lineCoverage()[$view])->toBe([1 => ['sonda'], 2 => [], 3 => ['sonda']])
        ->and(array_keys($coverage->getData()->lineCoverage()))->toEqualCanonicalizing([$other, $view])
        ->and($coverage->filter()->files())->toBe([$other, $view]);
});

it('keeps a view out of the report when it is not a source file', function (): void {
    $root = TemporaryDirectory::create();
    $view = $root . '/home.blade.php';
    $other = $root . '/Other.php';
    file_put_contents($other, "<?php\n\nfunction other(): int\n{\n    return 1;\n}\n");
    new InstrumentationCache($root . '/cache')->store('view', new InstrumentationResult('<?php', [0 => new Probe(1, false, '{main}', 0, 0)], [1 => 1], "<?php \$a = 1;"));
    $run = new Run($root . '/cache', ['v' => [$view, 'view']], ['v' => [0 => 1]]);

    expect(array_keys(CoverageBuilder::build($run, [$other])->getData()->lineCoverage()))->toBe([$other]);
});

it('leaves out the annotated code of a file the run never loaded, and keeps its deprecated code, as php-code-coverage does', function (): void {
    $root = TemporaryDirectory::create();
    $never = $root . '/Never.php';
    file_put_contents($never, "<?php\n\nfinal class Never\n{\n    /** @codeCoverageIgnore */\n    public function ignored(): int\n    {\n        return 1;\n    }\n\n    /** @deprecated */\n    public function old(): int\n    {\n        return 2;\n    }\n}\n");

    $coverage = CoverageBuilder::build(new Run($root . '/cache', [], []), [$never]);

    expect($coverage->getData()->lineCoverage()[$never])->toBe([14 => []]);
});

it('reports a view and a source file side by side, each with its own data', function (): void {
    $root = TemporaryDirectory::create();
    $view = $root . '/home.blade.php';
    $other = $root . '/Other.php';
    file_put_contents($view, "<h1>{{ \$title }}</h1>\n");
    file_put_contents($other, "<?php\n\nfunction other(): int\n{\n    return 1;\n}\n");
    $compiled = "<h1><?php echo e(\$title); ?></h1>\n";
    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('view', new InstrumentationResult($compiled, [0 => new Probe(1, false, 'view', 0, 0)], [1 => 1, 2 => 1], $compiled));
    $cache->store('other', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'other', 5, 5)]));

    $run = new Run($root . '/cache', ['v' => [$view, 'view'], 'o' => [$other, 'other']], ['v' => [0 => 1], 'o' => [0 => 1]]);

    $coverage = CoverageBuilder::build($run, [$other, $view]);

    expect($coverage->getData()->lineCoverage())->toBe([$other => [5 => ['sonda']], $view => [1 => ['sonda']]])
        ->and($coverage->getReport()->numberOfExecutedLines())->toBe(2);
});

it('names the tests that ran each covered line and branch, and describes them', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    $order = $root . '/app/Order.php';
    file_put_contents($order, "<?php\n\nfinal class Order\n{\n    public function total(int \$a): int\n    {\n        if (\$a > 1) {\n            return 1;\n        }\n\n        return 2;\n    }\n}\n");
    new InstrumentationCache($root . '/cache')->store('key', new InstrumentationResult('<?php', [
        0 => new Probe(8, true, 'Order->total', 7, 9),
        1 => new Probe(11, true, 'Order->total', 11, 11),
    ]));
    $run = new Run($root . '/cache', ['o' => [$order, 'key']], ['o' => [0 => 1]], [
        'OrderTest::test_large' => ['/tests/OrderTest.php', 'small', false, 0.25, ['o' => pack('V', 0)]],
        'OrderTest::test_none' => ['/tests/OrderTest.php', 'unknown', true, 0.0, []],
    ]);

    $coverage = CoverageBuilder::build($run, [$order], coveredBy: true);
    $branches = $coverage->getData()->functionCoverage()[$order]['Order->total']->branches;

    expect($coverage->getData()->lineCoverage()[$order])->toBe([8 => ['OrderTest::test_large'], 11 => []])
        ->and(array_map(static fn (ProcessedBranchCoverageData $branch): array => $branch->hit, $branches))->toBe([['OrderTest::test_large'], []])
        ->and($branches[0]->line_start)->toBe(7)
        ->and($branches[0]->line_end)->toBe(9)
        ->and($coverage->getTests())->toBe([
            'OrderTest::test_large' => ['size' => 'small', 'status' => 'failure', 'time' => 0.25],
            'OrderTest::test_none' => ['size' => 'unknown', 'status' => 'success', 'time' => 0.0],
        ]);
});

it('names the tests of a view on the lines of its template', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/resources/views', 0777, true);
    $view = $root . '/resources/views/home.blade.php';
    file_put_contents($view, "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");
    $compiled = "<?php \$a = 1; ?>\n<?php \$b = 2; ?>\n<?php \$c = 3; ?>\n";
    $probes = [new Probe(1, false, '{main}', 0, 0), new Probe(2, false, '{main}', 0, 0), new Probe(3, false, '{main}', 0, 0)];
    new InstrumentationCache($root . '/cache')->store('view', new InstrumentationResult($compiled, $probes, [1 => 2, 2 => 2, 3 => 4], $compiled));
    // Compiled line 1 ran in A and B, line 2 in A and C: template line 2 names each test once.
    $run = new Run($root . '/cache', ['v' => [$view, 'view']], ['v' => [0 => 1, 1 => 1]], [
        'A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['v' => pack('V*', 0, 1)]],
        'B::b' => ['/tests/B.php', 'unknown', true, 0.0, ['v' => pack('V', 0)]],
        'C::c' => ['/tests/C.php', 'unknown', true, 0.0, ['v' => pack('V', 1)]],
    ]);

    expect(CoverageBuilder::build($run, [$view], coveredBy: true)->getData()->lineCoverage())->toBe([$view => [2 => ['A::a', 'B::b', 'C::c'], 4 => []]]);
});

it('names the tests of a probed line on the other lines of its block, which php-code-coverage marks executed with it', function (): void {
    $root = TemporaryDirectory::create();
    $order = $root . '/Order.php';
    $unused = $root . '/Unused.php';
    // The return spans lines 5 to 8, one block; line 10 is a block of its own.
    file_put_contents($order, "<?php\n\nfunction total(int \$a): int\n{\n    return max(\n        \$a,\n        2,\n    );\n\n    \$b = 1;\n}\n");
    file_put_contents($unused, "<?php\n\nfunction unused(): int\n{\n    return 1;\n}\n");
    // Probe 2, on the blank line 9, is no executable line; probe 3 sits on line 6, in the block of probe 0.
    new InstrumentationCache($root . '/cache')->store('key', new InstrumentationResult('<?php', [
        0 => new Probe(5, false, 'total', 0, 0),
        1 => new Probe(10, false, 'total', 0, 0),
        2 => new Probe(9, false, 'total', 0, 0),
        3 => new Probe(6, false, 'total', 0, 0),
    ]));
    $run = new Run($root . '/cache', ['o' => [$order, 'key']], ['o' => [0 => 1, 1 => 1, 2 => 1, 3 => 1]], [
        'A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['o' => pack('V*', 0, 2)]],
        'B::b' => ['/tests/B.php', 'unknown', true, 0.0, ['o' => pack('V*', 0, 1)]],
        'C::c' => ['/tests/C.php', 'unknown', true, 0.0, ['o' => pack('V', 3)]],
    ]);

    $lines = CoverageBuilder::build($run, [$order, $unused], coveredBy: true)->getData()->lineCoverage();
    ksort($lines[$order]);

    expect($lines[$order])->toBe([5 => ['A::a', 'B::b', 'C::c'], 6 => ['A::a', 'B::b', 'C::c'], 7 => ['A::a', 'B::b', 'C::c'], 8 => ['A::a', 'B::b', 'C::c'], 10 => ['B::b']])
        ->and($lines[$unused])->toBe([5 => []]);
});

it('credits every covered line to the single test sonda unless asked to name the tests', function (): void {
    $root = TemporaryDirectory::create();
    $order = $root . '/Order.php';
    file_put_contents($order, "<?php\n\nfunction total(): int\n{\n    return 1;\n}\n");
    new InstrumentationCache($root . '/cache')->store('key', new InstrumentationResult('<?php', [0 => new Probe(5, false, 'total', 0, 0)]));
    $run = new Run($root . '/cache', ['o' => [$order, 'key']], ['o' => [0 => 1]], ['A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['o' => pack('V', 0)]]]);

    $coverage = CoverageBuilder::build($run, [$order]);

    expect($coverage->getData()->lineCoverage()[$order])->toBe([5 => ['sonda']])
        ->and(array_keys($coverage->getTests()))->toBe(['sonda']);
});
