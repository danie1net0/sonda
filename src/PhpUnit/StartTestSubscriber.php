<?php

declare(strict_types=1);

namespace CovProto\PhpUnit;

use CovProto\Hits;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class StartTestSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        Hits::startTest();
    }
}
