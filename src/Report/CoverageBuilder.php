<?php

declare(strict_types=1);

namespace Sonda\Report;

use ReflectionProperty;
use Sonda\Run;
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};
use SebastianBergmann\CodeCoverage\Data\{ProcessedBranchCoverageData, ProcessedFunctionCoverageData};
use SebastianBergmann\CodeCoverage\StaticAnalysis\{FileAnalyser, ParsingSourceAnalyser};

use function str_ends_with;

/**
 * A php-code-coverage object holding a run, ready for any of its reports.
 *
 * A Blade view enters through the analysis copy of its compiled code, whose lines php-code-coverage
 * can tell executable from not; once appended, its lines and branches are mapped back to the
 * template and the file takes the template's path, so every report shows the view itself. The
 * reports keep reading the copy for the view's code units: it declares the function `view` whose
 * branches they count.
 */
final class CoverageBuilder
{
    /** @param list<string> $sourceFiles only these files enter the report, covered or not */
    public static function build(Run $run, array $sourceFiles): CodeCoverage
    {
        $views = self::views($run, $sourceFiles);
        $appended = self::coverage([...$sourceFiles, ...array_column($views, 0)]);
        $appended->append(RawCoverageBuilder::build($run, $sourceFiles), 'sonda');

        $data = $appended->getData(true);
        $lines = $data->lineCoverage();
        $functions = $data->functionCoverage();

        foreach ($views as $viewPath => [$analysisPath, $lineMap]) {
            $lines[$viewPath] = self::mapped($lines[$analysisPath] ?? [], $lineMap);
            $functions[$viewPath] = self::mappedFunctions($functions[$analysisPath] ?? [], $lineMap);
            unset($lines[$analysisPath], $functions[$analysisPath]);
        }

        $data->setLineCoverage($lines);
        $data->setFunctionCoverage($functions);

        $coverage = self::coverage(self::reported($sourceFiles, $views), array_map(static fn (array $view): string => $view[0], $views));
        $coverage->setData($data);
        $coverage->setTests($appended->getTests());

        return $coverage;
    }

    /**
     * The views of the run that enter the report, with their analysis copies and line maps.
     *
     * @param list<string> $sourceFiles
     *
     * @return array<string, array{0: string, 1: array<int, int>}> view path => [analysis path, compiled line => template line]
     */
    private static function views(Run $run, array $sourceFiles): array
    {
        $isSource = array_flip($sourceFiles);
        $views = [];

        foreach ($run->files as [$path, $cacheKey]) {
            $lineMap = isset($isSource[$path]) ? $run->lineMapOf($cacheKey) : null;

            if ($lineMap !== null) {
                $views[$path] = [$run->analysisPathOf($cacheKey), $lineMap];
            }
        }

        return $views;
    }

    /**
     * The source files that enter the report: a view only with the data of a run, since
     * php-code-coverage cannot tell the executable lines of a template on its own.
     *
     * @param list<string>                                        $sourceFiles
     * @param array<string, array{0: string, 1: array<int, int>}> $views
     *
     * @return list<string>
     */
    private static function reported(array $sourceFiles, array $views): array
    {
        $reported = [];

        foreach ($sourceFiles as $file) {
            if (! str_ends_with($file, '.blade.php') || isset($views[$file])) {
                $reported[] = $file;
            }
        }

        return $reported;
    }

    /**
     * @param list<string>          $files
     * @param array<string, string> $copies view path => analysis copy, read in place of the template
     */
    private static function coverage(array $files, array $copies = []): CodeCoverage
    {
        $filter = new Filter();
        $filter->includeFiles($files);

        $coverage = new CodeCoverage(new CoverageDriver(), $filter);
        $coverage->enableBranchAndPathCoverage();
        // php-code-coverage builds its analyser itself, with these same two settings: the views need their own.
        new ReflectionProperty(CodeCoverage::class, 'analyser')->setValue($coverage, new FileAnalyser(new ViewSourceAnalyser($copies, new ParsingSourceAnalyser()), true, false));

        return $coverage;
    }

    /**
     * The line data of a compiled view on the lines of its template: a template line is
     * executed by the tests of all its compiled lines, each test once. php-code-coverage
     * never marks a line fed by sonda as not executable, so none comes as null here.
     *
     * @param array<int, list<string>|null> $compiledLines
     * @param array<int, int>               $lineMap
     *
     * @return array<int, list<string>|null>
     */
    private static function mapped(array $compiledLines, array $lineMap): array
    {
        $lines = [];

        foreach ($compiledLines as $compiledLine => $tests) {
            $templateLine = $lineMap[$compiledLine] ?? $compiledLine;
            $lines[$templateLine] = array_values(array_unique([...($lines[$templateLine] ?? []), ...($tests ?? [])]));
        }

        ksort($lines);

        return $lines;
    }

    /**
     * The branches of a compiled view on the lines of its template. The line map never shrinks
     * the order of lines, so a branch keeps its ends.
     *
     * @param array<string, ProcessedFunctionCoverageData> $functions
     * @param array<int, int>                              $lineMap
     *
     * @return array<string, ProcessedFunctionCoverageData>
     */
    private static function mappedFunctions(array $functions, array $lineMap): array
    {
        $mapped = [];

        foreach ($functions as $name => $function) {
            $branches = [];

            foreach ($function->branches as $id => $branch) {
                $branches[$id] = new ProcessedBranchCoverageData($branch->op_start, $branch->op_end, $lineMap[$branch->line_start], $lineMap[$branch->line_end], $branch->hit, $branch->out, $branch->out_hit);
            }

            $mapped[$name] = new ProcessedFunctionCoverageData($branches, $function->paths);
        }

        return $mapped;
    }
}
