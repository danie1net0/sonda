<?php

declare(strict_types=1);

use Sonda\Report\CoverageDriver;
use Sonda\Report\Compat\{CoverageApis, Coverage12AndOlder};
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

beforeEach(function (): void {
    if (onCoverage14()) {
        $this->markTestSkipped('php-code-coverage 11 and 12 only');
    }
});

/** The trait alone, as the apis of php-code-coverage 11 and 12 use it. */
final class Coverage12AndOlderUser
{
    use Coverage12AndOlder;
}

it('puts the analyser in the property where a report keeps it', function (): void {
    $coverage = new CodeCoverage(new CoverageDriver(), new Filter());
    $analyser = CoverageApis::current()->analyser([]);

    new Coverage12AndOlderUser()->useAnalyser($coverage, $analyser);

    expect(new ReflectionProperty(CodeCoverage::class, 'analyser')->getValue($coverage))->toBe($analyser);
});

it('keeps the tests of a line or a branch as their ids', function (): void {
    $hits = new Coverage12AndOlderUser()->testHits(new ProcessedCodeCoverageData(), ['A::a', 'B::b']);

    expect($hits(['B::b', 'A::a']))->toBe(['B::b', 'A::a'])
        ->and($hits([]))->toBe([]);
});

it('names each test of two merged lines once', function (): void {
    expect(new Coverage12AndOlderUser()->mergedHits(['A::a', 'B::b'], ['B::b', 'C::c']))->toBe(['A::a', 'B::b', 'C::c']);
});

it('hands the coverage itself to the report writers', function (): void {
    $coverage = new CodeCoverage(new CoverageDriver(), new Filter());

    expect(new Coverage12AndOlderUser()->reportInput($coverage))->toBe($coverage);
});

it('writes a .cov file that loads back as the report', function (): void {
    $file = TemporaryDirectory::create() . '/coverage.cov';

    new Coverage12AndOlderUser()->writeCov(new CodeCoverage(new CoverageDriver(), new Filter()), $file);

    expect(require $file)->toBeInstanceOf(CodeCoverage::class);
});
