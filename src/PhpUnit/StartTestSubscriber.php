<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class StartTestSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        Hits::startTest();
    }
}
