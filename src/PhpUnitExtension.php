<?php declare(strict_types=1);

namespace CovProto;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Only count probes hit while a test runs (same window PHPUnit gives Xdebug). */
final class PhpUnitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        R::$perTest = true;

        $facade->registerSubscribers(
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    R::$h = [];
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    foreach (R::$h as $fid => $hits) {
                        if (!is_array($hits)) {
                            continue;
                        }
                        R::$acc[$fid] = (R::$acc[$fid] ?? []) + $hits;
                    }
                    R::$h = [];
                }
            },
        );
    }
}
