<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\FinishTestSubscriber;
use PHPUnit\Event\Test\Finished;

it('keeps the probes hit by a finished test', function (): void {
    Hits::$current = ['abc' => [1 => 1]];

    (new FinishTestSubscriber)->notify((new ReflectionClass(Finished::class))->newInstanceWithoutConstructor());

    expect(Hits::$accumulated)->toBe(['abc' => [1 => 1]])
        ->and(Hits::$current)->toBe([]);
});
