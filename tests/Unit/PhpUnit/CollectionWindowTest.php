<?php

declare(strict_types=1);

use Sonda\Hits;
use Sonda\PhpUnit\{CollectionWindow, ErroredTestSubscriber, FailedTestSubscriber, FinishTestSubscriber, StartTestSubscriber};
use PHPUnit\Event\Test\{Errored, Failed, Finished, PreparationStarted};
use PHPUnit\Event\Facade as EventFacade;

/**
 * The event facade of the running suite is sealed, so the window is opened on a fresh one,
 * swapped in for the duration of the call.
 *
 * @return array<class-string, list<object>>
 */
function subscribersRegisteredByOpeningTheWindow(): array
{
    $instance = new ReflectionProperty(EventFacade::class, 'instance');
    $running = $instance->getValue();
    $fresh = new EventFacade();
    $instance->setValue(null, $fresh);

    try {
        expect(CollectionWindow::open())->toBeTrue();
    } finally {
        $instance->setValue(null, $running);
    }

    $deferring = new ReflectionProperty(EventFacade::class, 'deferringDispatcher')->getValue($fresh);
    $direct = new ReflectionProperty($deferring, 'dispatcher')->getValue($deferring);

    return new ReflectionProperty($direct, 'subscribers')->getValue($direct);
}

it('collects per test and subscribes to the start, the failure and the end of each test', function (): void {
    $subscribers = subscribersRegisteredByOpeningTheWindow();

    expect(Hits::$perTest)->toBeTrue()
        ->and($subscribers)->toHaveKeys([PreparationStarted::class, Failed::class, Errored::class, Finished::class])
        ->and($subscribers[Failed::class])->toHaveCount(1)
        ->and($subscribers[Failed::class][0])->toBeInstanceOf(FailedTestSubscriber::class)
        ->and($subscribers[Errored::class])->toHaveCount(1)
        ->and($subscribers[Errored::class][0])->toBeInstanceOf(ErroredTestSubscriber::class)
        ->and($subscribers[PreparationStarted::class])->toHaveCount(1)
        ->and($subscribers[PreparationStarted::class][0])->toBeInstanceOf(StartTestSubscriber::class)
        ->and($subscribers[Finished::class])->toHaveCount(1)
        ->and($subscribers[Finished::class][0])->toBeInstanceOf(FinishTestSubscriber::class);
});

it('counts nothing in a process whose events are already sealed, which runs no tests', function (): void {
    expect(CollectionWindow::open())->toBeFalse()
        ->and(Hits::$perTest)->toBeTrue()
        ->and(Hits::collected())->toBe([]);
});
