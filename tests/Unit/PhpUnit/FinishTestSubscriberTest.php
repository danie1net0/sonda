<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\FinishTestSubscriber;
use PHPUnit\Event\Code\Phpt;
use PHPUnit\Event\Test\Finished;
use Tests\Fixtures\Sizes\{LargeSizedTest, MediumSizedTest, OddFilenameTest, OlderPestLikeTest, PestLikeTest, SmallSizedTest, UnsizedTest};

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

    expect(Hits::$tests[$test->id()][1])->toBe($size)
        ->and(Hits::$problems)->toBe([]);
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
        ->and(Hits::$tests[$test->id()][0])->toBe('/project/tests/a.phpt')
        ->and(Hits::$problems)->toBe([]);
});

it('takes the real file of a Pest test, whose class PHPUnit sees as evaluated code', function (): void {
    $test = testMethod(PestLikeTest::class, 'test_it', "/project/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(178) : eval()'d code");

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$tests[$test->id()][0])->toBe('/project/tests/Feature/OrderTest.php');
});

it('keeps the file PHPUnit gives when the property Pest uses holds no file', function (): void {
    $test = testMethod(OddFilenameTest::class, 'test_it', '/project/tests/OddFilenameTest.php');

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$tests[$test->id()][0])->toBe('/project/tests/OddFilenameTest.php');
});

it('takes the real file of a test of Pest before 4.7, which keeps it private', function (): void {
    $test = testMethod(OlderPestLikeTest::class, 'test_it', "/project/vendor/pestphp/pest/src/Factories/TestCaseFactory.php(178) : eval()'d code");

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$tests[$test->id()][0])->toBe('/project/tests/Feature/OlderTest.php');
});

it('keeps the probes of a test whose details cannot be read, and says what went wrong', function (): void {
    Hits::$current = ['abc' => [2 => 1]];
    $test = testMethod('Tests\\Missing\\GoneTest', 'test_it', '/project/tests/GoneTest.php');

    new FinishTestSubscriber()->notify(testEvent(Finished::class, $test));

    expect(Hits::$accumulated)->toBe(['abc' => [2 => 1]])
        ->and(Hits::$tests[$test->id()][0])->toBe('/project/tests/GoneTest.php')
        ->and(Hits::$tests[$test->id()][1])->toBe('unknown')
        // What PHPUnit says of a class it cannot load depends on whether assertions run.
        ->and(array_values(Hits::$problems))->toBe([true])
        ->and((string) array_key_first(Hits::$problems))->not->toBe('');
});
