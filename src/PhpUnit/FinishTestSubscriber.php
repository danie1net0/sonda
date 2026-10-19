<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use Sonda\Hits;
use PHPUnit\Event\Code\{Test, TestMethod};
use PHPUnit\Event\Test\{Finished, FinishedSubscriber};
use PHPUnit\Metadata\Api\Groups;

final class FinishTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $test = $event->test();

        Hits::finishTest($test->id(), $test->file(), $this->size($test));
    }

    /** @return 'small'|'medium'|'large'|'unknown' the size PHPUnit gives the test's coverage */
    private function size(Test $test): string
    {
        if (! $test instanceof TestMethod) {
            return 'unknown';
        }

        $size = new Groups()->size($test->className(), $test->methodName());

        if ($size->isSmall()) {
            return 'small';
        }

        if ($size->isMedium()) {
            return 'medium';
        }

        return $size->isLarge() ? 'large' : 'unknown';
    }
}
