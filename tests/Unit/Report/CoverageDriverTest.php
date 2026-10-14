<?php

declare(strict_types=1);

use Sonda\Report\CoverageDriver;

it('names itself', function (): void {
    expect(new CoverageDriver()->nameAndVersion())->toBe('sonda');
});

it('collects nothing itself', function (): void {
    $driver = new CoverageDriver();

    $driver->start();

    expect($driver->stop())
        ->lineCoverage()->toBe([])
        ->functionCoverage()->toBe([]);
});
