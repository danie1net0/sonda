<?php

declare(strict_types=1);

namespace Sonda\PhpUnit;

use ReflectionProperty;
use Throwable;
use Sonda\Hits;
use PHPUnit\Event\Code\{Test, TestMethod};
use PHPUnit\Event\Test\{Finished, FinishedSubscriber};
use PHPUnit\Metadata\Api\Groups;

use function property_exists;

final class FinishTestSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $test = $event->test();

        // An exception here would reach PHPUnit as a mere warning and lose the probes of the test:
        // the details of the test give way, the probes are kept, and the command says what went wrong.
        try {
            $file = $this->file($test);
            $size = $this->size($test);
        } catch (Throwable $throwable) {
            Hits::$problems[$throwable->getMessage()] = true;
            $file = $test->file();
            $size = 'unknown';
        }

        Hits::finishTest($test->id(), $file, $size);
    }

    /**
     * The file of the test. Pest builds each test class with eval, so PHPUnit names the evaluated
     * code; the class keeps the real file in its static property `$__filename`.
     */
    private function file(Test $test): string
    {
        if (! $test instanceof TestMethod) {
            return $test->file();
        }

        if (! property_exists($test->className(), '__filename')) {
            return $test->file();
        }

        // Public since Pest 4.7, private before: reflection reads both.
        $filename = new ReflectionProperty($test->className(), '__filename')->getValue();

        // A class of another origin may hold anything in a property of that name.
        return is_string($filename) ? $filename : $test->file();
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
