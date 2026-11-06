<?php

declare(strict_types=1);

use Sonda\TestRecordPool;

it('gives back the same record', function (): void {
    $record = ['/tests/OrderTest.php', 'small', true, 0.5, ['abc' => pack('V*', 0, 3), '123' => pack('V', 1)]];

    expect(new TestRecordPool()->share($record))->toBe($record);
});

it('keeps a file id made only of digits as the key it was', function (): void {
    $record = ['/tests/OrderTest.php', 'small', true, 0.5, unserialize(serialize(['123' => pack('V', 1)]))];

    expect(array_key_first(new TestRecordPool()->share($record)[4]))->toBe(123);
});

/**
 * How much memory the records built by a callback take once the pool shares them. Each record
 * goes through serialize() and unserialize(), which give it copies of its own, as in a saved run.
 *
 * @param Closure(int): array{0: string, 1: 'small', 2: bool, 3: float, 4: array<string, string>} $record
 */
function sharedRecordsMemory(Closure $record): int
{
    $pool = new TestRecordPool();
    $shared = [];
    $before = memory_get_usage();

    for ($test = 0; $test < 100; $test++) {
        $shared[] = $pool->share(unserialize(serialize($record($test))));
    }

    return memory_get_usage() - $before;
}

/** A 10 KB string made at run time: unserialize() reuses an interned literal instead of copying it. */
function longText(): string
{
    return bin2hex(random_bytes(5000));
}

// Unshared, each case below would take 100 records × 10 KB = 1 MB.
it('keeps one copy of a test file the records repeat', function (): void {
    $testFile = longText();

    expect(sharedRecordsMemory(static fn (int $test): array => [$testFile, 'small', true, 0.5, []]))
        ->toBeLessThan(200_000);
});

it('keeps one copy of the packed probes the records repeat', function (): void {
    $packed = longText();

    expect(sharedRecordsMemory(static fn (int $test): array => ['/tests/T.php', 'small', true, 0.5, ['file' . $test => $packed]]))
        ->toBeLessThan(200_000);
});

it('keeps one copy of a file id the records repeat', function (): void {
    $fileId = longText();

    expect(sharedRecordsMemory(static fn (int $test): array => ['/tests/T.php', 'small', true, 0.5, [$fileId => pack('V', $test)]]))
        ->toBeLessThan(200_000);
});

it('keeps one copy of a hit set the records repeat', function (): void {
    $hitSet = static function (): array {
        $files = [];

        for ($file = 0; $file < 250; $file++) {
            $files['file' . $file] = pack('V', $file);
        }

        return $files;
    };

    expect(sharedRecordsMemory(static fn (int $test): array => ['/tests/T.php', 'small', true, 0.5, $hitSet()]))
        ->toBeLessThan(200_000);
});

it('does not mix two hit sets whose hashes collide', function (): void {
    $pool = new TestRecordPool();
    $hitSet = ['abc' => pack('V', 1)];
    $other = ['abc' => pack('V', 2)];
    $hitSets = new ReflectionProperty(TestRecordPool::class, 'hitSets');
    $hitSets->setValue($pool, [hash('xxh128', serialize($hitSet)) => $other]);

    expect($pool->share(['/tests/OrderTest.php', 'small', true, 0.5, $hitSet])[4])->toBe($hitSet);
});
