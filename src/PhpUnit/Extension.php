<?php

declare(strict_types=1);

namespace CovProto\PhpUnit;

use CovProto\Hits;
use PHPUnit\Runner\Extension\Extension as PhpUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Only counts probes hit while a test runs: the window PHPUnit gives Xdebug. */
final class Extension implements PhpUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        Hits::$perTest = true;

        $facade->registerSubscribers(new StartTestSubscriber, new FinishTestSubscriber);
    }
}
