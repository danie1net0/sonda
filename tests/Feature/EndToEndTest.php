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

/**
 * Measures the fixture project with a test runner, writes the report and reads it back.
 *
 * @param list<string> $runner the command, before the covproto options
 *
 * @return array{output: string, runFiles: int, lines: array<string, array<int, bool>>, branches: array<string, list<bool>>}
 */
function measureFixtureProject(array $runner): array
{
    $directory = TemporaryDirectory::create();
    $project   = dirname(__DIR__) . '/Fixtures/Project';

    $output = runCommand(
        [...$runner, '-c', $project . '/phpunit.xml', '--bootstrap', 'bootstrap.php'],
        [
            'COV_AUTOLOAD' => $project . '/autoload.php',
            'COV_TARGET'   => $project . '/src',
            'COV_CACHE'    => $directory . '/cache',
            'COV_OUT'      => $directory . '/runs',
            'COV_STRICT'   => '1',
            'COV_CHAIN'    => $project . '/chained.php',
        ],
    );
    $report = runCommand([PHP_BINARY, 'report.php', $directory . '/runs', $project . '/src', $directory . '/coverage.cov']);

    $coverage = require $directory . '/coverage.cov';
    $lines    = [];
    $branches = [];

    foreach ($coverage->getData()->lineCoverage() as $file => $fileLines) {
        $statuses = array_map(static fn (?array $tests): bool => $tests !== [] && $tests !== null, $fileLines);
        ksort($statuses);
        $lines[basename($file)] = $statuses;
    }

    foreach ($coverage->getData()->functionCoverage() as $functions) {
        foreach ($functions as $name => $function) {
            $branches[$name] = array_map(static fn (object $branch): bool => $branch->hit !== [], array_values($function->branches));
        }
    }

    expect($coverage)->toBeInstanceOf(CodeCoverage::class)
        ->and($report)->toContain('report built in');

    return ['output' => $output, 'runFiles' => count(glob($directory . '/runs/run-*.ser')), 'lines' => $lines, 'branches' => $branches];
}

/** What the fixture tests cover: code run only by the data provider does not count. */
function expectFixtureCoverage(array $measured): void
{
    expect($measured['lines'])->toBe([
        'Discount.php' => [11 => true, 12 => true, 15 => false, 20 => false],
        'Shipping.php' => [11 => true],
    ])
        ->and($measured['branches']['Fixture\Project\Discount->rate'])->toBe([true, true, false])
        ->and($measured['branches']['Fixture\Project\Shipping->cost'])->toBe([true, false, true]);
}

it('measures a PHPUnit run of a project and writes its report', function (): void {
    $measured = measureFixtureProject([PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage']);

    expect($measured['output'])->toContain('chained bootstrap ran')->toContain('OK (2 tests, 3 assertions)')
        ->and($measured['runFiles'])->toBe(1);

    expectFixtureCoverage($measured);
});

it('measures a parallel run, merging the run of each worker', function (): void {
    $measured = measureFixtureProject([PHP_BINARY, 'vendor/bin/paratest', '--processes', '2']);

    expect($measured['output'])->toContain('OK (2 tests, 3 assertions)')
        ->and($measured['runFiles'])->toBeGreaterThanOrEqual(2);

    expectFixtureCoverage($measured);
});
