<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\ErroredTestSubscriber;
use PHPUnit\Event\Test\Errored;
use Tests\Fixtures\Sizes\UnsizedTest;

it('marks a test that errored as failed', function (): void {
    $test = testMethod(UnsizedTest::class);

    new ErroredTestSubscriber()->notify(testEvent(Errored::class, $test));

    expect(Hits::$failed)->toBe([$test->id() => true]);
});
