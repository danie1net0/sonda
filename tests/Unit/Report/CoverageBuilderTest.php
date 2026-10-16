<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\CoverageBuilder;

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
        ->and(array_keys($coverage->getData()->functionCoverage()))->toBe([])
        ->and($coverage->filter()->files())->toBe([$view])
        ->and($coverage->getTests())->toHaveKey('sonda')
        ->and($report->numberOfExecutableLines())->toBe(3)
        ->and($report->numberOfExecutedLines())->toBe(2);
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
