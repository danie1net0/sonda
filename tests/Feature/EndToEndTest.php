<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * @param list<string>          $command
 * @param array<string, string> $environment
 */
function runCommand(array $command, array $environment = []): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), [...getenv(), ...$environment]);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the process.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, $output);

    return $output;
}

function loadCoverage(string $path): CodeCoverage
{
    $coverage = require $path;

    if (! $coverage instanceof CodeCoverage) {
        throw new RuntimeException("The file {$path} does not hold a report.");
    }

    return $coverage;
}

/**
 * Measures the fixture project with a test runner, writes the report and reads it back.
 *
 * @param list<string> $runner the command, before the sonda options
 *
 * @return array{output: string, runFiles: int, lines: array<string, array<int, bool>>, branches: array<string, list<bool>>}
 */
function measureFixtureProject(array $runner): array
{
    $directory = TemporaryDirectory::create();
    $project = __DIR__ . '/../Fixtures/Project';

    $output = runCommand(
        [...$runner, '-c', $project . '/phpunit.xml', '--bootstrap', 'bootstrap.php'],
        [
            'SONDA_AUTOLOAD' => $project . '/autoload.php',
            'SONDA_TARGET' => $project . '/src',
            'SONDA_EXCLUDE' => $project . '/src/Ignored',
            'SONDA_CACHE' => $directory . '/cache',
            'SONDA_OUT' => $directory . '/runs',
            'SONDA_STRICT' => '1',
            'SONDA_CHAIN' => $project . '/chained.php',
        ],
    );
    $report = runCommand([PHP_BINARY, 'report.php', $directory . '/runs', $project . '/src', $directory . '/coverage.cov', $project . '/src/Ignored']);

    $coverage = loadCoverage($directory . '/coverage.cov');
    $lines = [];
    $branches = [];

    foreach ($coverage->getData()->lineCoverage() as $file => $fileLines) {
        $statuses = array_map(static fn (?array $tests): bool => $tests !== [] && $tests !== null, $fileLines);
        ksort($statuses);
        $lines[basename($file)] = $statuses;
    }

    foreach ($coverage->getData()->functionCoverage() as $functions) {
        foreach ($functions as $name => $function) {
            $branches[$name] = array_map(
                static fn (array $branch): bool => $branch['hit'] !== [],
                branchesOf($function),
            );
        }
    }

    expect($report)->toContain('report built in');

    return [
        'output' => $output,
        'runFiles' => count(glob($directory . '/runs/run-*.ser') ?: []),
        'lines' => $lines,
        'branches' => $branches,
    ];
}

/**
 * What the fixture tests cover: code run only by the data provider does not count.
 *
 * @param array{lines: array<string, array<int, bool>>, branches: array<string, list<bool>>} $measured
 */
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

it('refuses to build a report without the required arguments', function (): void {
    $process = proc_open([PHP_BINARY, 'report.php', '/runs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the process.');
    }

    $error = stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(1)
        ->and($error)->toContain('usage: report.php');
});
