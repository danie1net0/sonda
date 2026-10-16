<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Run;
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};

use function str_ends_with;

/**
 * A php-code-coverage object holding a run, ready for any of its reports.
 *
 * A Blade view enters through the analysis copy of its compiled code, whose lines php-code-coverage
 * can tell executable from not; once appended, its lines are mapped back to the template and the
 * file takes the template's path, so every report shows the view itself.
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
            unset($lines[$analysisPath], $functions[$analysisPath]);
        }

        $data->setLineCoverage($lines);
        $data->setFunctionCoverage($functions);

        $coverage = self::coverage(self::reported($sourceFiles, $views));
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

    /** @param list<string> $files */
    private static function coverage(array $files): CodeCoverage
    {
        $filter = new Filter();
        $filter->includeFiles($files);

        $coverage = new CodeCoverage(new CoverageDriver(), $filter);
        $coverage->enableBranchAndPathCoverage();

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
}
