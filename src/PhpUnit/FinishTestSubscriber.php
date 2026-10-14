<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\Test\{Finished, FinishedSubscriber};

final class FinishTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        Hits::finishTest();
    }
}
