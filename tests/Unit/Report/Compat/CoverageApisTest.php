<?php

declare(strict_types=1);

use Sonda\Report\Compat\{CoverageApis, V11CoverageApi, V12CoverageApi, V14CoverageApi};

it('speaks to the php-code-coverage the project has installed', function (): void {
    $expected = V11CoverageApi::class;

    if (onCoverage12()) {
        $expected = V12CoverageApi::class;
    }

    if (onCoverage14()) {
        $expected = V14CoverageApi::class;
    }

    expect(CoverageApis::current())->toBeInstanceOf($expected);
});
