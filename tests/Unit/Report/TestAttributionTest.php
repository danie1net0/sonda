<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\TestAttribution;

/**
 * A run of /app/Order.php, whose probes 0 and 1 are branches on lines 3 and 4 and probe 2 a
 * plain line 5 that also covers line 6, and of /vendor/Lib.php, outside the report.
 *
 * @param array<string, array{0: string, 1: string, 2: bool, 3: float, 4: array<string, string>}> $tests
 */
function attributedRun(array $tests): Run
{
    $directory = TemporaryDirectory::create();
    new InstrumentationCache($directory)->store('order', new InstrumentationResult('<?php', [
        0 => new Probe(3, true, 'Order->total', 3, 3),
        1 => new Probe(4, true, 'Order->total', 4, 4),
        2 => new Probe(5, false, 'Order->total', 0, 0, [5, 6]),
    ]));

    return new Run($directory, ['o' => ['/app/Order.php', 'order'], 'l' => ['/vendor/Lib.php', 'order']], [], $tests);
}

it('names the tests that ran each line and each branch', function (): void {
    $run = attributedRun([
        'A::a' => ['/tests/A.php', 'small', true, 0.5, ['l' => pack('V', 0), 'o' => pack('V*', 0, 2)]],
        'B::b' => ['/tests/B.php', 'unknown', false, 1.5, ['o' => pack('V*', 1, 2)]],
    ]);

    $attribution = TestAttribution::of($run, ['/app/Order.php']);

    expect($attribution->lines)->toBe(['/app/Order.php' => [3 => ['A::a'], 5 => ['A::a', 'B::b'], 6 => ['A::a', 'B::b'], 4 => ['B::b']]])
        ->and($attribution->branches)->toBe(['/app/Order.php' => [0 => ['A::a'], 1 => ['B::b']]])
        ->and($attribution->tests)->toBe([
            'A::a' => ['size' => 'small', 'status' => 'success', 'time' => 0.5],
            'B::b' => ['size' => 'unknown', 'status' => 'failure', 'time' => 1.5],
        ]);
});

it('counts a line once for a test whose probes share it', function (): void {
    $directory = TemporaryDirectory::create();
    new InstrumentationCache($directory)->store('order', new InstrumentationResult('<?php', [
        0 => new Probe(3, false, '{main}', 0, 0),
        1 => new Probe(3, false, '{main}', 0, 0),
    ]));
    $run = new Run($directory, ['o' => ['/app/Order.php', 'order']], [], ['A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['o' => pack('V*', 0, 1)]]]);

    expect(TestAttribution::of($run, ['/app/Order.php'])->lines)->toBe(['/app/Order.php' => [3 => ['A::a']]]);
});

it('expands the same probes once for every test that hit them', function (): void {
    $run = attributedRun([
        'A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['o' => pack('V', 2)]],
        'B::b' => ['/tests/B.php', 'unknown', true, 0.0, ['o' => pack('V', 2)]],
    ]);

    expect(TestAttribution::of($run, ['/app/Order.php'])->lines)->toBe(['/app/Order.php' => [5 => ['A::a', 'B::b'], 6 => ['A::a', 'B::b']]]);
});

it('keeps a test that ran nothing of the report among the tests', function (): void {
    $attribution = TestAttribution::of(attributedRun(['A::a' => ['/tests/A.php', 'unknown', true, 0.0, ['l' => pack('V', 0)]]]), ['/app/Order.php']);

    expect($attribution->lines)->toBe([])
        ->and($attribution->branches)->toBe([])
        ->and($attribution->tests)->toHaveKey('A::a');
});
