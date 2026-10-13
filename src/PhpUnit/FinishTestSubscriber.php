<?php

declare(strict_types=1);

namespace CovProto\PhpUnit;

use CovProto\Hits;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class FinishTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        Hits::finishTest();
    }
}
