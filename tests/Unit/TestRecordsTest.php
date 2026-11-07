<?php

declare(strict_types=1);

use Sonda\TestRecords;

it('starts with no records nor pairs', function (): void {
    $records = new TestRecords();

    expect($records->records)->toBe([])
        ->and($records->pairs)->toBe([]);
});

it('keeps what each test hit, with its file, size, outcome and duration', function (): void {
    $records = new TestRecords();

    $records->add('A::a', '/tests/A.php', 'small', false, 0.5, ['abc' => pack('V*', 0, 3), 'def' => pack('V', 1)]);

    expect($records->records)->toBe(['A::a' => ['/tests/A.php', 'small', false, 0.5, pack('V*', 0, 1)]])
        ->and($records->pairs)->toBe([['abc', pack('V*', 0, 3)], ['def', pack('V', 1)]])
        ->and($records->pairsOf($records->records['A::a'][4]))->toBe([0 => ['abc', pack('V*', 0, 3)], 1 => ['def', pack('V', 1)]]);
});

it('keeps once a pair of a file and its probes that several tests hit', function (): void {
    $records = new TestRecords();

    $records->add('A::a', '/tests/A.php', 'unknown', true, 0.0, ['abc' => pack('V', 1), 'def' => pack('V', 2)]);
    $records->add('B::b', '/tests/B.php', 'unknown', true, 0.0, ['def' => pack('V', 2), 'abc' => pack('V', 3)]);

    expect($records->pairs)->toBe([['abc', pack('V', 1)], ['def', pack('V', 2)], ['abc', pack('V', 3)]])
        ->and($records->records['B::b'][4])->toBe(pack('V*', 1, 2));
});

it('keeps a test that hit nothing, with no pairs', function (): void {
    $records = new TestRecords();

    $records->add('A::a', '/tests/A.php', 'unknown', true, 0.0, []);

    expect($records->records['A::a'][4])->toBe('')
        ->and($records->pairsOf(''))->toBe([]);
});

it('keeps a file id made only of digits as the text it is', function (): void {
    $records = new TestRecords();

    $records->add('A::a', '/tests/A.php', 'unknown', true, 0.0, ['123' => pack('V', 1)]);

    expect($records->pairs)->toBe([['123', pack('V', 1)]]);
});

it('saves the records and the pairs, and finds the saved pairs again once loaded', function (): void {
    $records = new TestRecords();
    $records->add('A::a', '/tests/A.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    $serialized = serialize($records);
    $loaded = unserialize($serialized);
    $loaded->add('B::b', '/tests/B.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    expect($serialized)->not->toContain('pairIdLookup')
        ->and($loaded->records['A::a'])->toBe($records->records['A::a'])
        ->and($loaded->pairs)->toBe([['abc', pack('V', 1)]])
        ->and($loaded->records['B::b'][4])->toBe(pack('V', 0));
});

it('merges the records of several runs, moving their pairs into one table', function (): void {
    $first = new TestRecords();
    $first->add('A::a', '/tests/A.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    $second = new TestRecords();
    $second->add('B::b', '/tests/B.php', 'large', false, 1.5, ['def' => pack('V', 2), 'abc' => pack('V', 1)]);
    $second->add('C::c', '/tests/C.php', 'unknown', true, 0.0, ['def' => pack('V', 2)]);

    $merged = TestRecords::merge([$first, $second]);

    expect($merged->pairs)->toBe([['abc', pack('V', 1)], ['def', pack('V', 2)]])
        ->and($merged->records)->toBe([
            'A::a' => ['/tests/A.php', 'small', true, 0.5, pack('V', 0)],
            'B::b' => ['/tests/B.php', 'large', false, 1.5, pack('V*', 1, 0)],
            'C::c' => ['/tests/C.php', 'unknown', true, 0.0, pack('V', 1)],
        ])
        ->and($first->records)->toHaveCount(1)
        ->and($first->pairs)->toHaveCount(1);
});

it('keeps the record of the first run when two runs recorded the same test', function (): void {
    $first = new TestRecords();
    $first->add('A::a', '/tests/A.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    $second = new TestRecords();
    $second->add('A::a', '/tests/A.php', 'small', false, 9.0, ['def' => pack('V', 2)]);
    $second->add('B::b', '/tests/B.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    $merged = TestRecords::merge([$first, $second]);

    expect($merged->records)->toBe([
        'A::a' => ['/tests/A.php', 'small', true, 0.5, pack('V', 0)],
        'B::b' => ['/tests/B.php', 'small', true, 0.5, pack('V', 0)],
    ])
        ->and($merged->pairs)->toBe([['abc', pack('V', 1)]]);
});

it('gives back the records of a single run as they are', function (): void {
    $records = new TestRecords();
    $records->add('A::a', '/tests/A.php', 'small', true, 0.5, ['abc' => pack('V', 1)]);

    expect(TestRecords::merge([$records]))->toEqual($records);
});
