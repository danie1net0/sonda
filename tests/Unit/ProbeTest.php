<?php

declare(strict_types=1);

use CovProto\Probe;

it('lists its extra lines and its own line', function (): void {
    $probe = new Probe(3, false, 'f', 3, 5, [4, 5]);

    expect($probe->lines())->toBe([4, 5, 3]);
});

it('has only the extra lines when it has no line of its own', function (): void {
    $probe = new Probe(null, true, 'f', 3, 3);

    expect($probe->lines())->toBe([]);
});

it('counts as a branch of a named function', function (): void {
    expect(new Probe(1, true, 'App\Order->total', 1, 2))->countsAsBranch()->toBeTrue();
});

it('does not count when it is not a branch', function (): void {
    expect(new Probe(1, false, 'App\Order->total', 1, 2))->countsAsBranch()->toBeFalse();
});

it('does not count branches of closures, hooks and file-level code', function (string $functionName): void {
    expect(new Probe(1, true, $functionName, 1, 2))->countsAsBranch()->toBeFalse();
})->with(['{closure}', '{hook}', '{main}']);
