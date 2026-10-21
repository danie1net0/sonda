<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\StaticAnalysis\AnalysisResult;
use Sonda\Report\ViewSourceAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\{ParsingSourceAnalyser, SourceAnalyser};

it('analyses a view by its analysis copy, and any other file by its own code', function (): void {
    $root = TemporaryDirectory::create();
    file_put_contents($root . '/copy.php', "<?php function view() {\n    echo 1;\n}\n");
    $analyser = new ViewSourceAnalyser([$root . '/home.blade.php' => $root . '/copy.php'], new ParsingSourceAnalyser());

    $view = $analyser->analyse($root . '/home.blade.php', "<h1>{{ \$title }}</h1>\n", true, false);
    $other = $analyser->analyse($root . '/Other.php', "<?php function other() {\n    echo 2;\n}\n", true, false);

    expect(array_keys($view->functions()))->toBe(['view'])
        ->and(array_keys($other->functions()))->toBe(['other']);
})->skip(! onCoverage12(), 'php-code-coverage 12 only');

it('hands the settings on to the analyser it wraps', function (): void {
    $inner = new class() implements SourceAnalyser {
        public array $calls = [];

        public function analyse(string $sourceCodeFile, string $sourceCode, bool $useAnnotationsForIgnoringCode, bool $ignoreDeprecatedCode): AnalysisResult
        {
            $this->calls[] = [$sourceCodeFile, $sourceCode, $useAnnotationsForIgnoringCode, $ignoreDeprecatedCode];

            return new ParsingSourceAnalyser()->analyse($sourceCodeFile, $sourceCode, $useAnnotationsForIgnoringCode, $ignoreDeprecatedCode);
        }
    };

    new ViewSourceAnalyser([], $inner)->analyse('/project/a.php', '<?php echo 1;', false, true);

    expect($inner->calls)->toBe([['/project/a.php', '<?php echo 1;', false, true]]);
})->skip(! onCoverage12(), 'php-code-coverage 12 only');
