<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\FailedTestSubscriber;
use PHPUnit\Event\Test\Failed;
use Tests\Fixtures\Sizes\UnsizedTest;

it('marks a test that failed as failed', function (): void {
    $test = testMethod(UnsizedTest::class);

    new FailedTestSubscriber()->notify(testEvent(Failed::class, $test));

    expect(Hits::$failed)->toBe([$test->id() => true]);
});
