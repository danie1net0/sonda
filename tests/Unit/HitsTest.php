<?php

declare(strict_types=1);

use CovProto\Hits;

it('starts a test with an empty window', function (): void {
    Hits::$current['a'][1] = 1;

    Hits::startTest();

    expect(Hits::$current)->toBe([]);
});

it('adds the probes of a finished test to the accumulated ones', function (): void {
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['a']     = [2 => 1];
    Hits::$current['b']     = [0 => 1];

    Hits::finishTest();

    expect(Hits::$accumulated)->toBe(['a' => [1 => 1, 2 => 1], 'b' => [0 => 1]])
        ->and(Hits::$current)->toBe([]);
});

it('collects only the accumulated probes when collecting per test', function (): void {
    Hits::$perTest         = true;
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['b']     = [0 => 1];

    expect(Hits::collected())->toBe(['a' => [1 => 1]]);
});

it('collects every probe hit when not collecting per test', function (): void {
    Hits::$accumulated['a'] = [1 => 1];
    Hits::$current['b']     = [0 => 1];

    expect(Hits::collected())->toBe(['b' => [0 => 1]]);
});

it('starts empty and collecting every probe', function (): void {
    $default = static fn (string $property): mixed => (new ReflectionProperty(Hits::class, $property))->getDefaultValue();

    expect($default('perTest'))->toBeFalse()
        ->and($default('current'))->toBe([])
        ->and($default('accumulated'))->toBe([]);
});
