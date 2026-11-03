<?php

declare(strict_types=1);

use Sonda\Report\Compat\{CoverageApi, ObjectCoverageData, Coverage12AndOlder, V12CoverageApi};

beforeEach(function (): void {
    if (! onCoverage12() || onCoverage14()) {
        $this->markTestSkipped('php-code-coverage 12 only');
    }
});

it('speaks to php-code-coverage 12 through its objects and its test id lists', function (): void {
    expect(V12CoverageApi::class)->toImplement(CoverageApi::class)
        ->and(class_uses(V12CoverageApi::class))->toEqualCanonicalizing([ObjectCoverageData::class => ObjectCoverageData::class, Coverage12AndOlder::class => Coverage12AndOlder::class]);
});
