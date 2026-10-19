<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\FinishTestSubscriber;
use PHPUnit\Event\Code\Phpt;
use PHPUnit\Event\Test\Finished;
use Tests\Fixtures\Sizes\{LargeSizedTest, MediumSizedTest, SmallSizedTest, UnsizedTest};

it('keeps the probes hit by a finished test, under its id and file', function (): void {
    Hits::$current = ['abc' => [1 => 1]];
    $test = testMethod(UnsizedTest::class, 'test_it', '/project/tests/UnsizedTest.php');

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$accumulated)->toBe(['abc' => [1 => 1]])
        ->and(Hits::$current)->toBe([])
        ->and(Hits::$tests)->toHaveKey($test->id())
        ->and(Hits::$tests[$test->id()][0])->toBe('/project/tests/UnsizedTest.php')
        ->and(Hits::$tests[$test->id()][4])->toBe(['abc' => pack('V', 1)]);
});

it('carries the size PHPUnit gives the test', function (string $className, string $size): void {
    $test = testMethod($className);

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$tests[$test->id()][1])->toBe($size);
})->with([
    'small' => [SmallSizedTest::class, 'small'],
    'medium' => [MediumSizedTest::class, 'medium'],
    'large' => [LargeSizedTest::class, 'large'],
    'without a size' => [UnsizedTest::class, 'unknown'],
]);

it('gives a test that is not a method no size', function (): void {
    $test = new Phpt('/project/tests/a.phpt');

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$tests[$test->id()][1])->toBe('unknown')
        ->and(Hits::$tests[$test->id()][0])->toBe('/project/tests/a.phpt');
});
