<?php

declare(strict_types=1);

use Sonda\Report\Compat\{CoverageApi, CoverageApis};

it('is the contract the api of the installed php-code-coverage fulfils', function (): void {
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), new ReflectionClass(CoverageApi::class)->getMethods());

    expect(CoverageApis::current())->toBeInstanceOf(CoverageApi::class)
        ->and($methods)->toContain('branches', 'testHits', 'writeCov');
});
