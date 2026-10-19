<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\{CoverageBuilder, CoverageDriver, Uncovered};
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;

/**
 * A project with two source files: Order.php partly covered, Paid.php fully covered.
 *
 * @return array{root: string, order: string, paid: string, run: Run}
 */
function partlyCoveredProject(): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    $order = $root . '/app/Order.php';
    $paid = $root . '/app/Paid.php';
    file_put_contents($order, <<<'PHP'
    <?php

    final class Order
    {
        public function total(int $quantity): int
        {
            if ($quantity > 10) {
                return 10;
            }

            $total = $quantity;
            $total++;
            $total--;

            return $total;
        }
    }
    PHP);
    file_put_contents($paid, "<?php\n\nfinal class Paid\n{\n    public function yes(): bool\n    {\n        return true;\n    }\n}\n");

    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('order', new InstrumentationResult('<?php', [
        0 => new Probe(7, true, 'Order->total', 7, 15),
        1 => new Probe(8, true, 'Order->total', 8, 8),
        2 => new Probe(11, false, 'Order->total', 0, 0),
        3 => new Probe(12, false, 'Order->total', 0, 0),
        4 => new Probe(13, false, 'Order->total', 0, 0),
        5 => new Probe(15, false, 'Order->total', 0, 0),
        6 => new Probe(null, true, 'Order->total', 9, 9),
    ]));
    $cache->store('paid', new InstrumentationResult('<?php', [0 => new Probe(7, true, 'Paid->yes', 7, 7)]));

    $run = new Run($root . '/cache', ['o' => [$order, 'order'], 'p' => [$paid, 'paid']], ['o' => [0 => 1, 2 => 1, 5 => 1, 6 => 1], 'p' => [0 => 1]]);

    return ['root' => $root, 'order' => $order, 'paid' => $paid, 'run' => $run];
}

it('lists the lines and branches a report leaves uncovered, per file', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();

    $uncovered = Uncovered::of(CoverageBuilder::build($run, [$order, $paid]));

    expect($uncovered)->toBe([
        $order => ['lines' => [8, 12, 13], 'branches' => [[8, 8]]],
    ]);
});

it('orders the files and the branches whatever order the run gives them, across directories', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app/Models', 0777, true);
    mkdir($root . '/app/Zoo', 0777, true);
    $aaa = $root . '/app/Models/Aaa.php';
    $zzz = $root . '/app/Zoo/Zzz.php';
    file_put_contents($aaa, "<?php\n\nfunction aaa(bool \$flag): int\n{\n    return \$flag ? 1 : 2;\n}\n");
    file_put_contents($zzz, "<?php\n\nfunction zzz(): int\n{\n    return 1;\n}\n");
    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('aaa', new InstrumentationResult('<?php', [
        0 => new Probe(5, true, 'aaa', 5, 5),
        1 => new Probe(null, true, 'aaa', 20, 20),
        2 => new Probe(null, true, 'aaa', 8, 9),
    ]));
    $cache->store('zzz', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'zzz', 5, 5)]));

    $run = new Run($root . '/cache', ['a' => [$aaa, 'aaa'], 'z' => [$zzz, 'zzz']], ['a' => [0 => 1]]);

    expect(Uncovered::of(CoverageBuilder::build($run, [$zzz, $aaa])))->toBe([
        $aaa => ['lines' => [], 'branches' => [[8, 9], [20, 20]]],
        $zzz => ['lines' => [5], 'branches' => [[5, 5]]],
    ]);
});

it('describes the gaps with ranges and paths relative to the root', function (): void {
    $uncovered = [
        '/project/app/Order.php' => ['lines' => [3, 4, 5, 9, 11], 'branches' => [[8, 8], [20, 23]]],
        '/elsewhere/Thing.php' => ['lines' => [], 'branches' => [[1, 1]]],
        '/project/app/Paid.php' => ['lines' => [2], 'branches' => []],
    ];

    expect(Uncovered::describe($uncovered, '/project/'))->toBe(
        "app/Order.php: linhas 3-5, 9, 11; branches 8, 20-23\n"
        . "/elsewhere/Thing.php: branches 1\n"
        . 'app/Paid.php: linhas 2',
    );
});

it('describes nothing when everything is covered', function (): void {
    expect(Uncovered::describe([], '/project'))->toBe('');
});

it('counts the branches of methods, trait methods and functions, not those of closures or first-class callables', function (): void {
    $root = TemporaryDirectory::create();
    $file = $root . '/Order.php';
    file_put_contents($file, "<?php\n\ntrait Totals\n{\n    public function sum(): int\n    {\n        return 3;\n    }\n}\n\nfinal class Order\n{\n    public function never(): int\n    {\n        return 2;\n    }\n\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");
    $branch = static fn (int $line, int $hit): array => ['op_start' => 0, 'op_end' => 0, 'line_start' => $line, 'line_end' => $line, 'hit' => $hit, 'out' => [], 'out_hit' => []];
    $raw = RawCodeCoverageData::fromXdebugWithPathCoverage([
        $file => [
            'lines' => [7 => 1, 19 => 1],
            'functions' => [
                'Order->total' => ['branches' => [0 => $branch(19, 0)], 'paths' => []],
                'Totals->sum' => ['branches' => [0 => $branch(7, 0)], 'paths' => []],
                'total' => ['branches' => [0 => $branch(19, 0)], 'paths' => []],
                '{closure:Order.php:19-19}' => ['branches' => [0 => $branch(19, 0)], 'paths' => []],
            ],
        ],
    ]);
    $filter = new Filter();
    $filter->includeFile($file);

    $coverage = new CodeCoverage(new CoverageDriver(), $filter);
    $coverage->append($raw, 'test');

    // Lines absent from the raw data are not executable to php-code-coverage: `never()` shows nowhere.
    expect(Uncovered::of($coverage))->toBe([$file => ['lines' => [], 'branches' => [[7, 7], [19, 19]]]]);
});

it('narrows the gaps of a changed file to its changed lines and the methods they fall in', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();
    $coverage = CoverageBuilder::build($run, [$order, $paid]);

    // Line 12 is inside Order->total (lines 5-16): the whole method is checked; Paid.php, absent from the changes, is checked whole.
    $change = Uncovered::ofChanges($coverage, [$order => [12]]);

    expect($change)
        ->uncovered->toBe([$order => ['lines' => [8, 12, 13], 'branches' => [[8, 8]]]])
        ->executableLines->toBe(7)
        ->executedLines->toBe(4)
        ->executableBranches->toBe(4)
        ->executedBranches->toBe(3);
});

it('checks nothing of a changed file when the changed lines fall outside every method and executable line', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();
    $coverage = CoverageBuilder::build($run, [$order, $paid]);

    // Line 4 opens the class, line 17 closes it; line 3 of Paid.php declares the class.
    $change = Uncovered::ofChanges($coverage, [$order => [4, 17], $paid => [3]]);

    expect($change)
        ->uncovered->toBe([])
        ->executableLines->toBe(0)
        ->executedLines->toBe(0)
        ->executableBranches->toBe(0)
        ->executedBranches->toBe(0);
});

it('takes a change on the first or the last line of a method as touching it', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();
    $coverage = CoverageBuilder::build($run, [$order, $paid]);

    $first = Uncovered::ofChanges($coverage, [$order => [5], $paid => [3]]);
    $last = Uncovered::ofChanges($coverage, [$order => [16], $paid => [3]]);

    expect($first->uncovered)->toBe([$order => ['lines' => [8, 12, 13], 'branches' => [[8, 8]]]])
        ->and($first->executableLines)->toBe(6)
        ->and($first->executableBranches)->toBe(3)
        ->and($last->uncovered)->toBe($first->uncovered)
        ->and($last->executableLines)->toBe(6);
});

it('checks a new file whole', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();
    $coverage = CoverageBuilder::build($run, [$order, $paid]);

    $change = Uncovered::ofChanges($coverage, [$order => null, $paid => [3]]);

    expect($change)
        ->uncovered->toBe([$order => ['lines' => [8, 12, 13], 'branches' => [[8, 8]]]])
        ->executableLines->toBe(6)
        ->executedLines->toBe(3);
});

it('checks only the function the change falls in, up to its last line, among several in a file', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    $file = $root . '/app/functions.php';
    file_put_contents($file, "<?php\n\nfunction first(): int\n{\n    return 1;\n}\n\nfunction second(int \$flag): int\n{\n    if (\$flag > 0) {\n        return 2;\n    }\n\n    return 3; }\n");
    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('functions', new InstrumentationResult('<?php', [
        0 => new Probe(5, true, 'first', 3, 6),
        1 => new Probe(10, true, 'second', 8, 14),
        2 => new Probe(11, true, 'second', 11, 11),
        3 => new Probe(14, false, 'second', 0, 0),
    ]));
    $run = new Run($root . '/cache', ['f' => [$file, 'functions']], ['f' => [1 => 1]]);
    $coverage = CoverageBuilder::build($run, [$file]);

    // Line 9 opens second(): first() stays out, and the last line of second(), 14, is checked too.
    $change = Uncovered::ofChanges($coverage, [$file => [9]]);

    expect($change)
        ->uncovered->toBe([$file => ['lines' => [11, 14], 'branches' => [[11, 11]]]])
        ->executableLines->toBe(3)
        ->executedLines->toBe(1)
        ->executableBranches->toBe(2)
        ->executedBranches->toBe(1);
});

it('checks a changed executable line outside every function', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    $file = $root . '/app/script.php';
    file_put_contents($file, "<?php\n\nfunction first(): int\n{\n    return 1;\n}\n\necho first();\necho 2;\n");
    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('script', new InstrumentationResult('<?php', [
        0 => new Probe(5, true, 'first', 3, 6),
        1 => new Probe(8, false, '{main}', 0, 0),
        2 => new Probe(9, false, '{main}', 0, 0),
    ]));
    $run = new Run($root . '/cache', ['s' => [$file, 'script']], ['s' => [0 => 1, 1 => 1]]);
    $coverage = CoverageBuilder::build($run, [$file]);

    $change = Uncovered::ofChanges($coverage, [$file => [8, 9]]);

    expect($change)
        ->uncovered->toBe([$file => ['lines' => [9], 'branches' => []]])
        ->executableLines->toBe(2)
        ->executedLines->toBe(1)
        ->executableBranches->toBe(0);
});

it('checks only the files given, leaving the rest of the report out of the verdict and of the counts', function (): void {
    ['order' => $order, 'paid' => $paid, 'run' => $run] = partlyCoveredProject();
    $coverage = CoverageBuilder::build($run, [$order, $paid]);

    $onlyPaid = Uncovered::ofChanges($coverage, [], [$paid]);
    $all = Uncovered::ofChanges($coverage, []);

    $paidAlone = Uncovered::ofChanges(CoverageBuilder::build($run, [$paid]), []);

    expect($onlyPaid->uncovered)->toBe([])
        ->and($onlyPaid->executableLines)->toBeGreaterThan(0)
        ->toBe($paidAlone->executableLines)
        ->and($onlyPaid->executedLines)->toBe($paidAlone->executedLines)
        ->and($onlyPaid->executableLines)->toBeLessThan($all->executableLines)
        ->and($all->uncovered)->toHaveKey($order);
});
