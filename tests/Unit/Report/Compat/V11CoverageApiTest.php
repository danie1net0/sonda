<?php

declare(strict_types=1);

use Sonda\Report\Compat\{V11CoverageApi, V11ViewFileAnalyser};
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

beforeEach(function (): void {
    if (onCoverage12()) {
        $this->markTestSkipped('php-code-coverage 11 only');
    }
});

it('analyses a view by its analysis copy, and any other file by its own code', function (): void {
    $root = TemporaryDirectory::create();
    file_put_contents($root . '/home.blade.php', "<h1>{{ \$title }}</h1>\n");
    file_put_contents($root . '/copy.php', "<?php function view() {\n    echo 1;\n}\n");
    file_put_contents($root . '/Other.php', "<?php\n\$a = 1;\n\n\$b = [\n    2,\n];\n");
    $api = new V11CoverageApi();

    $analyser = $api->analyser([$root . '/home.blade.php' => $root . '/copy.php']);

    expect($analyser)->toBeInstanceOf(V11ViewFileAnalyser::class)
        ->and(array_keys($analyser->functionsIn($root . '/home.blade.php')))->toBe(['view'])
        ->and($api->executableLines($analyser, $root . '/Other.php'))->toBe([2 => 1, 4 => 2, 5 => 2, 6 => 2]);
});

it('hands the branches of a function out and takes them back, in the arrays php-code-coverage 11 keeps', function (): void {
    $api = new V11CoverageApi();
    $function = ['branches' => [3 => compatBranch(7, 10, ['A::a'])], 'paths' => [5 => ['path' => [3], 'hit' => []]]];

    $branches = $api->branches($function);
    $branches[3]['hit'] = ['B::b'];

    expect($api->branches($function))->toBe([3 => compatBranch(7, 10, ['A::a'])])
        ->and($api->withBranches($function, $branches))->toBe(['branches' => [3 => compatBranch(7, 10, ['B::b'])], 'paths' => [5 => ['path' => [3], 'hit' => []]]]);
});

it('puts the processed data of the functions back into the data of a report', function (): void {
    $data = new ProcessedCodeCoverageData();
    $functions = ['/app/A.php' => ['a' => ['branches' => [0 => compatBranch(0, 3, [])], 'paths' => []]]];

    new V11CoverageApi()->setFunctionCoverage($data, $functions);

    expect($data->functionCoverage())->toBe($functions);
});

it('lists the functions and the methods of classes and traits a report node counts, with their spans', function (): void {
    expect(new V11CoverageApi()->functionSpans(compatFileNode()))->toBe(['helper' => [3, 6], 'Order->total' => [10, 13], 'Named->name' => [18, 21]]);
});
