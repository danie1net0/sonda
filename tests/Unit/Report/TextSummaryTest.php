<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\{CoverageBuilder, TextSummary};

it('lists each file with its lines and branches, relative to the root and sorted', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app/Models', 0777, true);
    $order = $root . '/app/Order.php';
    $never = $root . '/app/Models/Never.php';
    file_put_contents($order, "<?php\n\nfunction order(int \$a): int\n{\n    if (\$a > 1) {\n        return 1;\n    }\n\n    return 2;\n}\n");
    file_put_contents($never, "<?php\n\nfunction never(): int\n{\n    return 1;\n}\n");
    $probes = [];
    $probes[] = new Probe(5, true, 'order', 5, 9);
    $probes[] = new Probe(6, true, 'order', 6, 6);
    $probes[] = new Probe(9, false, 'order', 0, 0);
    new InstrumentationCache($root . '/cache')->store('order', new InstrumentationResult('<?php', $probes));
    $coverage = CoverageBuilder::build(new Run($root . '/cache', ['o' => [$order, 'order']], ['o' => [0 => 1, 2 => 1]]), [$order, $never]);

    expect(TextSummary::of($coverage, $root))->toBe("app/Models/Never.php: linhas 0/1 (0,00%), branches 0/0 (100,00%)\napp/Order.php: linhas 2/3 (66,67%), branches 1/2 (50,00%)")
        ->and(TextSummary::of($coverage, $root . '/'))->toStartWith('app/Models/Never.php: linhas 0/1')
        ->and(TextSummary::of($coverage, '/elsewhere'))->toStartWith($root . '/app/Models/Never.php: linhas 0/1');
});

it('lists the files in path order, whatever order the run met them in', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    file_put_contents($root . '/app/Zeta.php', "<?php\n\nfunction zeta(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Alpha.php', "<?php\n\nfunction alpha(): int\n{\n    return 1;\n}\n");
    $cache = new InstrumentationCache($root . '/cache');
    $cache->store('zeta', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'zeta', 5, 5)]));
    $cache->store('alpha', new InstrumentationResult('<?php', [0 => new Probe(5, true, 'alpha', 5, 5)]));

    $coverage = CoverageBuilder::build(new Run($root . '/cache', ['z' => [$root . '/app/Zeta.php', 'zeta'], 'a' => [$root . '/app/Alpha.php', 'alpha']], ['z' => [0 => 1], 'a' => [0 => 1]]), [$root . '/app/Zeta.php', $root . '/app/Alpha.php']);

    expect(TextSummary::of($coverage, $root))->toBe("app/Alpha.php: linhas 1/1 (100,00%), branches 1/1 (100,00%)\napp/Zeta.php: linhas 1/1 (100,00%), branches 1/1 (100,00%)");
});

it('formats percentages the Brazilian way, a whole as all covered', function (): void {
    expect(TextSummary::percentage(0, 0))->toBe('100,00%')
        ->and(TextSummary::percentage(1, 3))->toBe('33,33%')
        ->and(TextSummary::ratio(0, 0))->toBe(100.0)
        ->and(TextSummary::ratio(1, 4))->toBe(25.0)
        ->and(TextSummary::format(1234.5))->toBe('1.234,50%');
});

it('keeps a path outside the root as it is', function (): void {
    expect(TextSummary::relative('/project/app/A.php', '/project'))->toBe('app/A.php')
        ->and(TextSummary::relative('/project/app/A.php', '/project/'))->toBe('app/A.php')
        ->and(TextSummary::relative('/elsewhere/A.php', '/project'))->toBe('/elsewhere/A.php');
});
