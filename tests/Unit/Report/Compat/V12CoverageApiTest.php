<?php

declare(strict_types=1);

use Sonda\Report\Compat\V12CoverageApi;
use SebastianBergmann\CodeCoverage\Data\{ProcessedBranchCoverageData, ProcessedCodeCoverageData, ProcessedFunctionCoverageData};
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;

beforeEach(function (): void {
    if (! onCoverage12()) {
        $this->markTestSkipped('php-code-coverage 12 only');
    }
});

it('analyses a view by its analysis copy, and any other file by its own code', function (): void {
    $root = TemporaryDirectory::create();
    file_put_contents($root . '/home.blade.php', "<h1>{{ \$title }}</h1>\n");
    file_put_contents($root . '/copy.php', "<?php function view() {\n    echo 1;\n}\n");
    file_put_contents($root . '/Other.php', "<?php\n\$a = 1;\n\n\$b = [\n    2,\n];\n");
    $api = new V12CoverageApi();

    $analyser = $api->analyser([$root . '/home.blade.php' => $root . '/copy.php']);

    expect($analyser)->toBeInstanceOf(FileAnalyser::class)
        ->and(array_keys($analyser->analyse($root . '/home.blade.php')->functions()))->toBe(['view'])
        ->and($api->executableLines($analyser, $root . '/Other.php'))->toBe([2 => 1, 4 => 2, 5 => 2, 6 => 2]);
});

it('hands the branches of a function out as arrays and takes them back as its objects', function (): void {
    $api = new V12CoverageApi();
    $function = new ProcessedFunctionCoverageData([3 => new ProcessedBranchCoverageData(7, 8, 10, 11, ['A::a'], [1 => 2], [1 => 1])], [5 => ['path' => [3], 'hit' => []]]);

    $branches = $api->branches($function);
    $branches[3]['hit'] = ['B::b'];
    $changed = $api->withBranches($function, $branches);

    expect($api->branches($function))->toBe([3 => ['op_start' => 7, 'op_end' => 8, 'line_start' => 10, 'line_end' => 11, 'hit' => ['A::a'], 'out' => [1 => 2], 'out_hit' => [1 => 1]]])
        ->and($changed)->toBeInstanceOf(ProcessedFunctionCoverageData::class)
        ->and($changed->branches[3]->hit)->toBe(['B::b'])
        ->and($changed->branches[3]->line_start)->toBe(10)
        ->and($changed->paths)->toBe($function->paths);
});

it('puts the processed data of the functions back into the data of a report', function (): void {
    $api = new V12CoverageApi();
    $data = new ProcessedCodeCoverageData();
    $functions = ['/app/A.php' => ['a' => $api->withBranches(new ProcessedFunctionCoverageData([], []), [0 => compatBranch(0, 3, [])])]];

    $api->setFunctionCoverage($data, $functions);

    expect($data->functionCoverage())->toBe($functions);
});

it('lists the functions and the methods of classes and traits a report node counts, with their spans', function (): void {
    expect(new V12CoverageApi()->functionSpans(compatFileNode()))->toBe(['helper' => [3, 6], 'Order->total' => [10, 13], 'Named->name' => [18, 21]]);
});
