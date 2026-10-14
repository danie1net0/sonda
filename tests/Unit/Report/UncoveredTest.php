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
