<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\{EventFacadeIsSealedException, Facade as EventFacade};

/**
 * Only counts probes hit while a test runs: the window PHPUnit gives Xdebug. Also prepares the
 * Blade views once a test has booted the Laravel application.
 *
 * Opened from the bootstrap, which PHPUnit and every ParaTest worker run before sealing their
 * event facade. An extension would not do: ParaTest does not pass `--extension` to its workers.
 * The event facade is internal to PHPUnit: sonda supports the PHPUnit 11 and 12 it is tested on.
 */
final class CollectionWindow
{
    public static function open(): void
    {
        Hits::$perTest = true;

        try {
            EventFacade::instance()->registerSubscribers(new StartTestSubscriber(), new PreparedSubscriber(), new FailedTestSubscriber(), new ErroredTestSubscriber(), new FinishTestSubscriber());
        } catch (EventFacadeIsSealedException) {
            // The parallel orchestrator loads the bootstrap after sealing, and runs no tests:
            // with the window never opened, nothing it executes counts as covered.
        }
    }
}
