<?php

declare(strict_types=1);

use Sonda\Cli\Version;
use Sonda\Report\CoverageDriver;

it('names itself', function (): void {
    $driver = new CoverageDriver();

    expect($driver->nameAndVersion())->toBe('sonda')
        ->and($driver->name())->toBe('sonda')
        ->and($driver->version())->toBe(Version::of(dirname(__DIR__, 3)));
});

it('lets the reports of every php-code-coverage count branches and paths', function (): void {
    $driver = new CoverageDriver();

    // php-code-coverage 14 asks through the two protected methods, 11 and 12 through the public one.
    expect($driver->canCollectBranchAndPathCoverage())->toBeTrue()
        ->and(new ReflectionMethod($driver, 'canCollectBranchCoverage')->invoke($driver))->toBeTrue()
        ->and(new ReflectionMethod($driver, 'canCollectPathCoverage')->invoke($driver))->toBeTrue();
});

it('collects nothing itself', function (): void {
    $driver = new CoverageDriver();

    $driver->start();

    expect($driver->stop())
        ->lineCoverage()->toBe([])
        ->functionCoverage()->toBe([]);
});
