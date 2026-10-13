<?php

declare(strict_types=1);

use CovProto\Hits;
use CovProto\PhpUnit\Extension;
use CovProto\PhpUnit\FinishTestSubscriber;
use CovProto\PhpUnit\StartTestSubscriber;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The event facade of the running suite is sealed, so the extension registers its
 * subscribers in a fresh one, swapped in for the duration of the call.
 *
 * @return array<class-string, list<object>>
 */
function subscribersRegisteredBy(Extension $extension): array
{
    $instance = new ReflectionProperty(EventFacade::class, 'instance');
    $running  = $instance->getValue();
    $fresh    = new EventFacade;
    $instance->setValue(null, $fresh);

    try {
        $extension->bootstrap(
            (new ReflectionClass(Configuration::class))->newInstanceWithoutConstructor(),
            new Facade,
            ParameterCollection::fromArray([]),
        );
    } finally {
        $instance->setValue(null, $running);
    }

    $deferring = (new ReflectionProperty(EventFacade::class, 'deferringDispatcher'))->getValue($fresh);
    $direct    = (new ReflectionProperty($deferring, 'dispatcher'))->getValue($deferring);

    return (new ReflectionProperty($direct, 'subscribers'))->getValue($direct);
}

it('collects per test and subscribes to the start and end of each test', function (): void {
    $subscribers = subscribersRegisteredBy(new Extension);

    expect(Hits::$perTest)->toBeTrue()
        ->and($subscribers)->toHaveKeys([PreparationStarted::class, Finished::class])
        ->and($subscribers[PreparationStarted::class])->toHaveCount(1)
        ->and($subscribers[PreparationStarted::class][0])->toBeInstanceOf(StartTestSubscriber::class)
        ->and($subscribers[Finished::class])->toHaveCount(1)
        ->and($subscribers[Finished::class][0])->toBeInstanceOf(FinishTestSubscriber::class);
});
