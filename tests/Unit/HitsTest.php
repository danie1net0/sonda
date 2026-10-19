<?php

declare(strict_types=1);

use Sonda\Hits;

it('starts a test with an empty window', function (): void {
    Hits::$current['a'][1] = 1;

    Hits::startTest();

    expect(Hits::$current)->toBe([]);
});

it('adds the probes of a finished test to the accumulated ones', function (): void {
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['a'] = [2 => 1];
    Hits::$current['b'] = [0 => 1];

    Hits::finishTest('T::t', '/tests/T.php', 'unknown');

    expect(Hits::$accumulated)->toBe(['a' => [1 => 1, 2 => 1], 'b' => [0 => 1]])
        ->and(Hits::$current)->toBe([]);
});

it('collects only the accumulated probes when collecting per test', function (): void {
    Hits::$perTest = true;
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['b'] = [0 => 1];

    expect(Hits::collected())->toBe(['a' => [1 => 1]]);
});

it('collects every probe hit when not collecting per test', function (): void {
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['b'] = [0 => 1];

    expect(Hits::collected())->toBe(['b' => [0 => 1]]);
});

it('starts empty and collecting every probe', function (): void {
    $default = static fn (string $property): mixed => new ReflectionProperty(Hits::class, $property)->getDefaultValue();

    expect($default('perTest'))->toBeFalse()
        ->and($default('current'))->toBe([])
        ->and($default('accumulated'))->toBe([])
        ->and($default('tests'))->toBe([])
        ->and($default('failed'))->toBe([])
        ->and($default('packed'))->toBe([])
        ->and($default('startedAt'))->toBe(0);
});

it('keeps the probes each test hit, with its file, size, outcome and duration', function (): void {
    Hits::startTest();
    Hits::$current['a'] = [3 => 1, 1 => 1];
    Hits::$current['b'] = [0 => 1];
    usleep(1000);

    Hits::finishTest('Tests\\OrderTest::test_total', '/project/tests/OrderTest.php', 'small');

    [$file, $size, $passed, $seconds, $hits] = Hits::$tests['Tests\\OrderTest::test_total'];

    expect($file)->toBe('/project/tests/OrderTest.php')
        ->and($size)->toBe('small')
        ->and($passed)->toBeTrue()
        ->and($seconds)->toBeGreaterThanOrEqual(0.001)->toBeLessThan(60.0)
        ->and($hits)->toBe(['a' => pack('V*', 3, 1), 'b' => pack('V', 0)]);
});

it('keeps a test that hit nothing, with no probes', function (): void {
    Hits::startTest();

    Hits::finishTest('Tests\\EmptyTest::test_it', '/project/tests/EmptyTest.php', 'unknown');

    expect(Hits::$tests['Tests\\EmptyTest::test_it'][1])->toBe('unknown')
        ->and(Hits::$tests['Tests\\EmptyTest::test_it'][4])->toBe([]);
});

it('marks a test that failed, and forgets the failure once it finishes', function (): void {
    Hits::startTest();
    Hits::failTest('Tests\\OrderTest::test_total');
    Hits::finishTest('Tests\\OrderTest::test_total', '/tests/OrderTest.php', 'unknown');
    Hits::startTest();
    Hits::finishTest('Tests\\OrderTest::test_other', '/tests/OrderTest.php', 'unknown');

    expect(Hits::$tests['Tests\\OrderTest::test_total'][2])->toBeFalse()
        ->and(Hits::$tests['Tests\\OrderTest::test_other'][2])->toBeTrue()
        ->and(Hits::$failed)->toBe([]);
});

it('shares one packed list among the tests that hit the same probes', function (): void {
    Hits::$current['a'] = [1 => 1, 2 => 1];
    Hits::finishTest('first', '/tests/A.php', 'unknown');
    Hits::$current['a'] = [1 => 1, 2 => 1];
    Hits::finishTest('second', '/tests/A.php', 'unknown');

    $packed = new ReflectionProperty(Hits::class, 'packed')->getValue();

    expect($packed)->toBe([pack('V*', 1, 2) => pack('V*', 1, 2)])
        ->and(Hits::$tests['first'][4])->toBe(Hits::$tests['second'][4]);
});

it('records the non-null outcome of ?-> and passes the value through', function (): void {
    $value = new stdClass();

    expect(Hits::nullsafe('a', 1, 2, $value))->toBe($value)
        ->and(Hits::$current)->toBe(['a' => [1 => 1]]);
});

it('records the null outcome of ?-> when it counts', function (): void {
    expect(Hits::nullsafe('a', 1, 2, null))->toBeNull()
        ->and(Hits::$current)->toBe(['a' => [2 => 1]]);
});

it('records nothing for a null value when only the non-null outcome counts', function (): void {
    expect(Hits::nullsafe('a', 1, null, null))->toBeNull()
        ->and(Hits::$current)->toBe([]);
});

it('records the left operand of a conjunction deciding alone and passes it through', function (): void {
    expect(Hits::shortCircuit('a', 1, false, 0))->toBe(0)
        ->and(Hits::shortCircuit('a', 2, false, ''))->toBe('')
        ->and(Hits::shortCircuit('a', 3, false, null))->toBeNull()
        ->and(Hits::$current)->toBe(['a' => [1 => 1, 2 => 1, 3 => 1]]);
});

it('records nothing for a conjunction when the right operand still runs', function (): void {
    $value = new stdClass();

    expect(Hits::shortCircuit('a', 1, false, $value))->toBe($value)
        ->and(Hits::shortCircuit('a', 2, false, '0.0'))->toBe('0.0')
        ->and(Hits::$current)->toBe([]);
});

it('records the left operand of a disjunction deciding alone and passes it through', function (): void {
    expect(Hits::shortCircuit('a', 1, true, [0]))->toBe([0])
        ->and(Hits::shortCircuit('a', 2, true, 'x'))->toBe('x')
        ->and(Hits::shortCircuit('a', 3, true, '0'))->toBe('0')
        ->and(Hits::$current)->toBe(['a' => [1 => 1, 2 => 1]]);
});

it('treats false and zero as values, not as null', function (): void {
    expect(Hits::nullsafe('a', 1, 2, false))->toBeFalse()
        ->and(Hits::nullsafe('a', 3, 4, 0))->toBe(0)
        ->and(Hits::$current)->toBe(['a' => [1 => 1, 3 => 1]]);
});
