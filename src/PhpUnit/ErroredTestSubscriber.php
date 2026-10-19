<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\Test\{Errored, ErroredSubscriber};

/** A test that errored still covers what it ran, and the report marks it as failed. */
final class ErroredTestSubscriber implements ErroredSubscriber
{
    public function notify(Errored $event): void
    {
        Hits::failTest($event->test()->id());
    }
}
