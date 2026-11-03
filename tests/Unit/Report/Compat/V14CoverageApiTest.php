<?php

declare(strict_types=1);

use Sonda\Report\CoverageDriver;
use Sonda\Report\Compat\{CoverageApi, ObjectCoverageData, V14CoverageApi};
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;
use SebastianBergmann\CodeCoverage\StaticAnalysis\Registry;

beforeEach(function (): void {
    if (! onCoverage14()) {
        $this->markTestSkipped('php-code-coverage 14 only');
    }
});

it('speaks to php-code-coverage 14 through its objects', function (): void {
    expect(V14CoverageApi::class)->toImplement(CoverageApi::class)
        ->and(class_uses(V14CoverageApi::class))->toBe([ObjectCoverageData::class => ObjectCoverageData::class]);
});

it('puts the analyser in the registry, where a report with the settings sonda leaves asks for it', function (): void {
    $api = new V14CoverageApi();
    $analyser = $api->analyser([]);

    $api->useAnalyser(new CodeCoverage(new CoverageDriver(), new Filter()), $analyser);

    expect(Registry::analyser(null, true, false))->toBe($analyser)
        ->and(Registry::analyser(null, false, false))->not->toBe($analyser);
});

it('keeps the tests of a line or a branch as indexes of the data, adding the tests it lacks', function (): void {
    $data = new ProcessedCodeCoverageData();
    $data->setTestIds([0 => 'sonda']);

    $hits = new V14CoverageApi()->testHits($data, ['A::a', 'sonda', 'B::b']);

    expect($hits(['B::b', 'sonda']))->toBe([2 => 1, 0 => 1])
        ->and($hits([]))->toBe([])
        ->and($data->testIds())->toBe([0 => 'sonda', 1 => 'A::a', 2 => 'B::b']);
});

it('starts the indexes at zero in data without tests', function (): void {
    $data = new ProcessedCodeCoverageData();

    $hits = new V14CoverageApi()->testHits($data, ['A::a']);

    expect($hits(['A::a']))->toBe([0 => 1])
        ->and($data->testIds())->toBe([0 => 'A::a']);
});

it('names each test of two merged lines once', function (): void {
    expect(new V14CoverageApi()->mergedHits([0 => 1, 1 => 1], [1 => 1, 2 => 1]))->toBe([0 => 1, 1 => 1, 2 => 1]);
});

it('hands the tree of the report to the report writers', function (): void {
    expect(new V14CoverageApi()->reportInput(new CodeCoverage(new CoverageDriver(), new Filter())))->toBeInstanceOf(Directory::class);
});

it('writes a .cov file with the serializer of php-code-coverage 14', function (): void {
    $file = TemporaryDirectory::create() . '/coverage.cov';

    new V14CoverageApi()->writeCov(new CodeCoverage(new CoverageDriver(), new Filter()), $file);

    expect(new Unserializer()->unserialize($file)['codeCoverage'])->toBeInstanceOf(ProcessedCodeCoverageData::class);
});
