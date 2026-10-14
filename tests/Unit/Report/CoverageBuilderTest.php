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
