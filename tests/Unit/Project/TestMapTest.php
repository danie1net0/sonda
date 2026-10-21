<?php

declare(strict_types=1);

use Sonda\Run;
use Sonda\Project\TestMap;
use Tests\Fixtures\RevivalWitness;

/**
 * A run whose tests in each test file hit the files given.
 *
 * @param array<string, list<string>> $hitsByTestFile test file => source files its single test hit
 */
function runOfTests(array $hitsByTestFile): Run
{
    $files = [];
    $tests = [];

    foreach ($hitsByTestFile as $testFile => $sources) {
        $packed = [];

        foreach ($sources as $source) {
            $fileId = substr(sha1($source), 0, 12);
            $files[$fileId] = [$source, 'key'];
            $packed[$fileId] = pack('V', 0);
        }

        $tests[$testFile . '::test'] = [$testFile, 'unknown', true, 0.0, $packed];
    }

    return new Run('/cache', $files, [], $tests);
}

/** Test files that exist, for the map to keep. @return array{0: string, 1: string, 2: string} */
function existingTestFiles(): array
{
    $directory = TemporaryDirectory::create();
    $files = [$directory . '/ATest.php', $directory . '/BTest.php', $directory . '/CTest.php'];

    foreach ($files as $file) {
        touch($file);
    }

    return $files;
}

it('starts empty when nothing was saved, and when the file holds something else', function (): void {
    $path = TemporaryDirectory::create() . '/tests.map';

    expect(TestMap::load($path, '/project')->sources)->toBe([]);

    file_put_contents($path, serialize(['not' => 'a map']));

    expect(TestMap::load($path, '/project')->sources)->toBe([]);
});

it('saves and loads a map, leaving no temporary file behind', function (): void {
    $directory = TemporaryDirectory::create();
    $map = new TestMap(['/project/tests/ATest.php' => ['/project/app/A.php', '/vendor/lib/B.php']]);

    $map->save($directory . '/maps/tests.map', '/project/');

    expect(TestMap::load($directory . '/maps/tests.map', '/project'))->toEqual($map)
        ->and(TestMap::load($directory . '/maps/tests.map', '/checkout/'))->toEqual(new TestMap(['/checkout/tests/ATest.php' => ['/checkout/app/A.php', '/vendor/lib/B.php']]))
        ->and((string) file_get_contents($directory . '/maps/tests.map'))->toContain('"tests/ATest.php"')->toContain('"app/A.php"')->not->toContain('/project')
        ->and(glob($directory . '/maps/*'))->toBe([$directory . '/maps/tests.map']);
});

it('saves a map in a directory it creates, leaving no temporary file behind', function (): void {
    $directory = TemporaryDirectory::create();
    $map = new TestMap(['/tests/ATest.php' => ['/app/A.php']]);

    $map->save($directory . '/tests.map', '/project');

    expect(TestMap::load($directory . '/tests.map', '/project'))->toEqual($map)
        ->and(glob($directory . '/*'))->toBe([$directory . '/tests.map']);
});

it('reads a map holding objects as an empty one, without reviving them', function (): void {
    $path = TemporaryDirectory::create() . '/tests.map';
    file_put_contents($path, serialize(new TestMap(['tests/ATest.php' => [new RevivalWitness()]])));
    RevivalWitness::$revived = false;

    expect(TestMap::load($path, '/project')->sources)->toBe([])
        ->and(RevivalWitness::$revived)->toBeFalse();
});

it('maps each test file of a run to the source files its tests ran, sorted', function (): void {
    [$a, $b] = existingTestFiles();

    $map = new TestMap()->updatedWith(runOfTests([$a => ['/app/Z.php', '/app/A.php'], $b => []]), ['/app/A.php', '/app/Z.php']);

    expect($map->sources)->toBe([$a => ['/app/A.php', '/app/Z.php'], $b => []]);
});

it('merges the tests of one test file, each source file once', function (): void {
    [$a] = existingTestFiles();
    $run = new Run('/cache', ['x' => ['/app/X.php', 'k'], 'y' => ['/app/Y.php', 'k'], 'z' => ['/app/Z.php', 'k']], [], [
        $a . '::first' => [$a, 'unknown', true, 0.0, ['x' => pack('V', 0), 'z' => pack('V', 0)]],
        $a . '::second' => [$a, 'unknown', true, 0.0, ['x' => pack('V', 1), 'y' => pack('V', 0)]],
    ]);

    expect(new TestMap()->updatedWith($run, ['/app/X.php', '/app/Y.php', '/app/Z.php'])->sources)->toBe([$a => ['/app/X.php', '/app/Y.php', '/app/Z.php']]);
});

it('replaces what a test file ran among the measured files and keeps the rest', function (): void {
    [$a, $b] = existingTestFiles();
    $before = new TestMap([$a => ['/app/Kept.php', '/app/Measured.php'], $b => ['/app/Other.php']]);

    $map = $before->updatedWith(runOfTests([$a => ['/app/New.php']]), ['/app/Measured.php', '/app/New.php']);

    expect($map->sources)->toBe([$a => ['/app/Kept.php', '/app/New.php'], $b => ['/app/Other.php']]);
});

it('forgets the test files gone from disk', function (): void {
    [$a] = existingTestFiles();

    $map = new TestMap(['/gone/GoneTest.php' => ['/app/A.php'], $a => ['/app/A.php']])->updatedWith(runOfTests([]), []);

    expect($map->sources)->toBe([$a => ['/app/A.php']]);
});

it('sorts the test files', function (): void {
    [$a, $b, $c] = existingTestFiles();

    $map = new TestMap([$c => [], $a => []])->updatedWith(runOfTests([$b => []]), []);

    expect(array_keys($map->sources))->toBe([$a, $b, $c]);
});

it('knows the source files some test runs', function (): void {
    $map = new TestMap(['/tests/ATest.php' => ['/app/A.php'], '/tests/BTest.php' => ['/app/B.php']]);

    expect($map->knows('/app/B.php'))->toBeTrue()
        ->and($map->knows('/app/C.php'))->toBeFalse();
});

it('lists the test files whose tests ran any of the source files', function (): void {
    $map = new TestMap(['/tests/ATest.php' => ['/app/A.php'], '/tests/BTest.php' => ['/app/B.php', '/app/C.php'], '/tests/CTest.php' => ['/app/C.php']]);

    expect($map->testsOf(['/app/C.php']))->toBe(['/tests/BTest.php', '/tests/CTest.php'])
        ->and($map->testsOf(['/app/A.php', '/app/B.php']))->toBe(['/tests/ATest.php', '/tests/BTest.php'])
        ->and($map->testsOf([]))->toBe([]);
});
