<?php

declare(strict_types=1);

use Sonda\Project\{AffectedTests, TestMap};

/**
 * A project with two test files, a test support file and a smoke test listed alone, and the map
 * of what the two test files run.
 *
 * @return array{root: string, map: TestMap, sources: list<string>, testPaths: list<string>}
 */
function affectedProject(): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/tests/Unit', 0777, true);

    foreach (['tests/Unit/OrderTest.php', 'tests/Unit/CartTest.php', 'tests/Unit/NewTest.php', 'tests/Pest.php', 'tests/smoke.php'] as $file) {
        touch($root . '/' . $file);
    }

    return [
        'root' => $root,
        'map' => new TestMap([
            $root . '/tests/Unit/CartTest.php' => [$root . '/app/Cart.php', $root . '/app/Money.php'],
            $root . '/tests/Unit/OrderTest.php' => [$root . '/app/Money.php', $root . '/app/Order.php'],
        ]),
        'sources' => [$root . '/app/Cart.php', $root . '/app/Money.php', $root . '/app/Order.php', $root . '/app/Fresh.php', $root . '/resources/views/home.blade.php'],
        'testPaths' => [$root . '/tests/Unit', $root . '/tests/smoke.php'],
    ];
}

it('runs the test files whose tests ran a changed source file, once each, sorted', function (): void {
    ['root' => $root, 'map' => $map, 'sources' => $sources, 'testPaths' => $testPaths] = affectedProject();

    expect(AffectedTests::of([$root . '/app/Order.php'], $map, $sources, $testPaths))
        ->tests->toBe([$root . '/tests/Unit/OrderTest.php'])
        ->reason->toBe('')
        ->and(AffectedTests::of([$root . '/app/Money.php', $root . '/app/Order.php'], $map, $sources, $testPaths))
        ->toEqual(new AffectedTests([$root . '/tests/Unit/CartTest.php', $root . '/tests/Unit/OrderTest.php']));
});

it('runs the test files that changed, mapped or new, and skips the deleted ones', function (): void {
    ['root' => $root, 'map' => $map, 'sources' => $sources, 'testPaths' => $testPaths] = affectedProject();
    unlink($root . '/tests/Unit/CartTest.php');

    $affected = AffectedTests::of([$root . '/tests/Unit/OrderTest.php', $root . '/tests/Unit/NewTest.php', $root . '/tests/Unit/CartTest.php', $root . '/tests/Unit/GoneTest.php'], $map, $sources, $testPaths);

    expect($affected->tests)->toBe([$root . '/tests/Unit/NewTest.php', $root . '/tests/Unit/OrderTest.php']);
});

it('runs a test file a suite lists alone when it changes', function (): void {
    ['root' => $root, 'map' => $map, 'sources' => $sources] = affectedProject();
    rename($root . '/tests/smoke.php', $root . '/tests/SmokeTest.php');

    expect(AffectedTests::of([$root . '/tests/SmokeTest.php'], $map, $sources, [$root . '/tests/SmokeTest.php'])->tests)->toBe([$root . '/tests/SmokeTest.php']);
});

it('runs the whole suite without a map', function (): void {
    ['root' => $root, 'sources' => $sources, 'testPaths' => $testPaths] = affectedProject();

    expect(AffectedTests::of([$root . '/app/Order.php'], new TestMap(), $sources, $testPaths))
        ->toEqual(new AffectedTests(null, 'this project has no test map yet; this run creates it'));
});

it('runs the whole suite for a source file no test is known to run, view or class', function (string $file): void {
    ['root' => $root, 'map' => $map, 'sources' => $sources, 'testPaths' => $testPaths] = affectedProject();

    expect(AffectedTests::of([$root . '/app/Order.php', $root . '/' . $file], $map, $sources, $testPaths))
        ->toEqual(new AffectedTests(null, "no known test runs {$root}/{$file}"));
})->with(['app/Fresh.php', 'resources/views/home.blade.php']);

it('runs the whole suite when a file that is neither source nor test changes', function (string $file): void {
    ['root' => $root, 'map' => $map, 'sources' => $sources, 'testPaths' => $testPaths] = affectedProject();

    expect(AffectedTests::of([$root . '/' . $file], $map, $sources, $testPaths))
        ->toEqual(new AffectedTests(null, "{$root}/{$file} changed and is neither a <source> file nor a test"));
})->with([
    'configuration' => 'config/app.php',
    'a deleted source file' => 'app/Removed.php',
    'a test support file' => 'tests/Pest.php',
    'a support file under a test directory' => 'tests/Unit/helpers.php',
    'a file beside a test directory with its name as prefix' => 'tests/UnitTest.php',
]);
