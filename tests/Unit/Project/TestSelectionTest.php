<?php

declare(strict_types=1);

use Sonda\Project\TestSelection;
use PHPUnit\TextUI\CliArguments\Builder;

it('tells the test paths from the options, leaving the configuration file out', function (): void {
    $selection = TestSelection::of(['--filter=x', 'tests/Unit', '-c', 'phpunit.xml', 'tests/Feature/ATest.php', '--configuration', 'a.xml', '--configuration=b.xml', '--no-progress']);

    expect($selection->options)->toBe(['--filter=x', '--no-progress'])
        ->and($selection->paths)->toBe(['tests/Unit', 'tests/Feature/ATest.php']);
});

it('finds no paths in options alone', function (): void {
    expect(TestSelection::of(['--filter=x']))->toEqual(new TestSelection(['--filter=x'], []))
        ->and(TestSelection::of([]))->toEqual(new TestSelection([], []));
});

it('keeps paths and options after an inline configuration', function (): void {
    expect(TestSelection::of(['--configuration=phpunit.xml', 'tests/Unit', '--stop-on-failure']))
        ->toEqual(new TestSelection(['--stop-on-failure'], ['tests/Unit']));
});

it('keeps option values together while finding paths on either side', function (): void {
    $selection = TestSelection::of(['tests/Unit', '--filter', 'orders edits', '--group', 'unit', '--processes', '2', 'tests/Feature', '--stop-on-failure']);

    expect($selection->options)->toBe(['--filter', 'orders edits', '--group', 'unit', '--processes', '2', '--stop-on-failure'])
        ->and($selection->paths)->toBe(['tests/Unit', 'tests/Feature']);
});

it('does not treat values that resemble paths or options as test selection', function (string $value): void {
    expect(TestSelection::of(['--filter', $value, '--no-progress']))
        ->toEqual(new TestSelection(['--filter', $value, '--no-progress'], []));
})->with(['tests/Unit', '--configuration', '--configuration=x.xml', '-c', '--', '']);

it('leaves missing values and inline values for the runner to validate', function (): void {
    expect(TestSelection::of(['--filter']))->toEqual(new TestSelection(['--filter'], []))
        ->and(TestSelection::of(['--filter=orders edits', 'tests/Unit']))
        ->toEqual(new TestSelection(['--filter=orders edits'], ['tests/Unit']));
});

it('keeps paths after flags with optional inline values', function (): void {
    expect(TestSelection::of(['--colors', 'tests/Unit', '--coverage-text', 'tests/Feature', '--unknown']))
        ->toEqual(new TestSelection(['--colors', '--coverage-text', '--unknown'], ['tests/Unit', 'tests/Feature']));
});

it('distinguishes Pest flags from PHPUnit and ParaTest options with values', function (string $option): void {
    expect(TestSelection::of([$option, 'tests/Unit', '--filter', 'orders edits', 'tests/Feature'], pest: true))
        ->toEqual(new TestSelection([$option, '--filter', 'orders edits'], ['tests/Unit', 'tests/Feature']))
        ->and(TestSelection::of([$option, '2', 'tests/Unit']))
        ->toEqual(new TestSelection([$option, '2'], ['tests/Unit']));
})->with(['-p', '--retry']);

it('treats every token after the runner separator as a path', function (): void {
    expect(TestSelection::of(['--filter', 'orders edits', '--', '-c', '--filter', '--', 'tests/Unit']))
        ->toEqual(new TestSelection(['--filter', 'orders edits'], ['-c', '--filter', '--', 'tests/Unit']));
});

/** @return Generator<string, array{string}> */
function runnerValueOptions(): Generator
{
    // Check the contract against every installed PHPUnit version in CI, so new options cannot
    // silently become paths. Production code does not depend on PHPUnit's internal constants.
    foreach (new ReflectionClass(Builder::class)->getConstant('LONG_OPTIONS') as $option) {
        if (! str_ends_with($option, '=') || str_ends_with($option, '==') || $option === 'configuration=') {
            continue;
        }

        $arguments = [];
        $arguments[] = '--' . substr($option, 0, -1);

        yield $option => $arguments;
    }

    foreach (['-d', '--max-batch-size', '-m', '--passthru-php', '--processes', '--max-processes', '--runner', '--tmp-dir', '--shard', '--shard-test-distribution', '--min', '--exactly', '--id', '--class', '--ignore', '--issue', '--pr', '--pull-request'] as $option) {
        $arguments = [];
        $arguments[] = $option;

        yield $option => $arguments;
    }
}

it('keeps separate values for runner selection, output and execution options', function (string $option): void {
    expect(TestSelection::of([$option, 'value', 'tests/Unit', '--no-progress']))
        ->toEqual(new TestSelection([$option, 'value', '--no-progress'], ['tests/Unit']));
})->with(runnerValueOptions());

it('recognizes PHPUnit 13 value options even when the instrumenter uses an older PHPUnit', function (string $option): void {
    expect(TestSelection::of([$option, 'value', 'tests/Unit']))
        ->toEqual(new TestSelection([$option, 'value'], ['tests/Unit']));
})->with(['--coverage-jsonl', '--diff-context', '--repeat', '--retry', '--run-test-id', '--test-files-file', '--test-id-filter-file', '--timeout']);
