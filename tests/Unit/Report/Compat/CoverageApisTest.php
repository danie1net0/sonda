<?php

declare(strict_types=1);

use Sonda\Report\Compat\{CoverageApis, V11CoverageApi, V12CoverageApi};

it('speaks to the php-code-coverage the project has installed', function (): void {
    expect(CoverageApis::current())->toBeInstanceOf(onCoverage12() ? V12CoverageApi::class : V11CoverageApi::class);
});
