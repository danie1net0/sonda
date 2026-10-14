<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\RawCoverageBuilder;

/** @param array<int, Probe> $probes */
function cachedRun(array $probes, array $hits, string $path = '/app/Order.php'): Run
{
    $directory = TemporaryDirectory::create();
    new InstrumentationCache($directory)->store('key', new InstrumentationResult('<?php', $probes));

    return new Run($directory, ['abc' => [$path, 'key']], $hits === [] ? [] : ['abc' => $hits]);
}

it('marks each probed line as executed or not', function (): void {
    $run = cachedRun([
        0 => new Probe(3, false, 'App\Order->total', 0, 0),
        1 => new Probe(4, false, 'App\Order->total', 0, 0),
    ], [0 => 1]);

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())
        ->toBe(['/app/Order.php' => [3 => 1, 4 => -1]]);
});

it('marks a line executed when any of its probes was hit', function (): void {
    $run = cachedRun([
        0 => new Probe(5, false, '{main}', 0, 0),
        1 => new Probe(5, false, '{main}', 0, 0),
        2 => new Probe(6, false, '{main}', 0, 0),
        3 => new Probe(6, false, '{main}', 0, 0),
    ], [1 => 1, 2 => 1]);

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())
        ->toBe(['/app/Order.php' => [5 => 1, 6 => 1]]);
});

it('gives the extra lines of a probe its status', function (): void {
    $run = cachedRun([0 => new Probe(null, true, '{closure}', 7, 9, [7, 8, 9])], [0 => 1]);

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())
        ->toBe(['/app/Order.php' => [7 => 1, 8 => 1, 9 => 1]]);
});

it('reports the branches of named functions in the shape Xdebug uses', function (): void {
    $run = cachedRun([
        0 => new Probe(3, true, 'App\Order->total', 3, 5),
        1 => new Probe(4, true, 'App\Order->total', 4, 4),
        2 => new Probe(9, true, '{closure}', 9, 9),
        3 => new Probe(10, false, 'App\Order->total', 0, 0),
    ], [0 => 1]);

    $branches = RawCoverageBuilder::build($run, ['/app/Order.php'])->functionCoverage()['/app/Order.php'];

    expect($branches)->toBe([
        'App\Order->total' => [
            'branches' => [
                0 => ['op_start' => 0, 'op_end' => 0, 'line_start' => 3, 'line_end' => 5, 'hit' => 1, 'out' => [], 'out_hit' => []],
                1 => ['op_start' => 1, 'op_end' => 1, 'line_start' => 4, 'line_end' => 4, 'hit' => 0, 'out' => [], 'out_hit' => []],
            ],
            'paths' => [],
        ],
    ]);
});

it('reports a file loaded without hits as not executed', function (): void {
    $run = cachedRun([0 => new Probe(3, false, '{main}', 0, 0)], []);

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())
        ->toBe(['/app/Order.php' => [3 => -1]]);
});

it('leaves out files that are not source files', function (): void {
    $run = cachedRun([0 => new Probe(3, false, '{main}', 0, 0)], [0 => 1], '/app/Providers/AppProvider.php');

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())->toBe([]);
});

it('keeps reading the files that follow one that is not a source file', function (): void {
    $directory = TemporaryDirectory::create();
    new InstrumentationCache($directory)->store('key', new InstrumentationResult('<?php', [0 => new Probe(3, false, '{main}', 0, 0)]));
    $run = new Run($directory, ['a' => ['/app/Providers/AppProvider.php', 'key'], 'b' => ['/app/Order.php', 'key']], ['b' => [0 => 1]]);

    expect(RawCoverageBuilder::build($run, ['/app/Order.php'])->lineCoverage())
        ->toBe(['/app/Order.php' => [3 => 1]]);
});
