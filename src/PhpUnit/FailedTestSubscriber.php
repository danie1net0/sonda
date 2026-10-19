<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\Test\{Failed, FailedSubscriber};

/** A failed test still covers what it ran, and the report marks it as failed. */
final class FailedTestSubscriber implements FailedSubscriber
{
    public function notify(Failed $event): void
    {
        Hits::failTest($event->test()->id());
    }
}
