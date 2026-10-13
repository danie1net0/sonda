<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;

/** @param array<string, string> $environment */
function runCommand(array $command, array $environment = []): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), [...getenv(), ...$environment]);
    $output  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $status  = proc_close($process);

    expect($status)->toBe(0, $output);

    return $output;
}

it('measures a PHPUnit run of a project and writes its report', function (): void {
    $directory = TemporaryDirectory::create();
    $project   = dirname(__DIR__) . '/Fixtures/Project';

    $output = runCommand(
        [PHP_BINARY, 'vendor/bin/phpunit', '-c', $project . '/phpunit.xml', '--bootstrap', 'bootstrap.php', '--extension', 'CovProto\PhpUnit\Extension', '--no-coverage'],
        [
            'COV_AUTOLOAD' => $project . '/autoload.php',
            'COV_TARGET'   => $project . '/src',
            'COV_CACHE'    => $directory . '/cache',
            'COV_OUT'      => $directory . '/run.ser',
            'COV_STRICT'   => '1',
            'COV_CHAIN'    => $project . '/chained.php',
        ],
    );
    $report = runCommand([PHP_BINARY, 'report.php', $directory . '/run.ser', $project . '/src', $directory . '/coverage.cov']);

    $coverage = require $directory . '/coverage.cov';
    $file     = (string) realpath($project . '/src/Discount.php');
    $lines    = array_map(static fn (?array $tests): bool => $tests !== [] && $tests !== null, $coverage->getData()->lineCoverage()[$file]);
    ksort($lines);
    $branches = $coverage->getData()->functionCoverage()[$file]['Fixture\Project\Discount->rate']->branches;

    expect($output)->toContain('chained bootstrap ran')->toContain('OK (1 test, 2 assertions)')
        ->and($report)->toContain('report built in')
        ->and($coverage)->toBeInstanceOf(CodeCoverage::class)
        ->and($lines)->toBe([11 => true, 12 => true, 15 => false, 20 => false])
        ->and(array_map(static fn (object $branch): bool => $branch->hit !== [], $branches))->toBe([true, true, false]);
});
