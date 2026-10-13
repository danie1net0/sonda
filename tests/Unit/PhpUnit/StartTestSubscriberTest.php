<?php

declare(strict_types=1);

use CovProto\Hits;
use CovProto\PhpUnit\StartTestSubscriber;
use PHPUnit\Event\Test\PreparationStarted;

it('opens an empty collection window when a test starts', function (): void {
    Hits::$current = ['abc' => [1 => 1]];

    (new StartTestSubscriber)->notify((new ReflectionClass(PreparationStarted::class))->newInstanceWithoutConstructor());

    expect(Hits::$current)->toBe([]);
});
