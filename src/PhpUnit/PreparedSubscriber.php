<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Illuminate\Container\Container;
use Sonda\Blade\Views;
use PHPUnit\Event\Test\{Prepared, PreparedSubscriber as PreparedSubscriberContract};

/**
 * After a test's setUp, the Laravel application exists: the views are prepared then, once.
 * Without Laravel loaded, or before the first test that boots it, there is nothing to do.
 */
final class PreparedSubscriber implements PreparedSubscriberContract
{
    public function notify(Prepared $event): void
    {
        Views::instance()?->prepareFromApplication(Container::class);
    }
}
