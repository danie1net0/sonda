<?php

declare(strict_types=1);

namespace Sonda\Report;

use ReflectionProperty;
use Sonda\Run;
use Sonda\Report\Compat\{CoverageApi, CoverageApis};
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};

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
    /**
     * @param list<string> $sourceFiles only these files enter the report, covered or not
     * @param bool         $coveredBy   whether each covered line and branch names the tests that ran it,
     *                                  instead of the single test `sonda`: heavy, with a large suite
     */
    public static function build(Run $run, array $sourceFiles, bool $coveredBy = false): CodeCoverage
    {
        $api = CoverageApis::current();
        $views = self::views($run, $sourceFiles);
        $appended = self::coverage($api, [...$sourceFiles, ...array_column($views, 0)]);
        $appended->append(RawCoverageBuilder::build($run, $sourceFiles), 'sonda');

        $data = $appended->getData(true);
        $lines = $data->lineCoverage();
        $functions = $data->functionCoverage();
        $tests = $appended->getTests();

        if ($coveredBy) {
            $attribution = TestAttribution::of($run, $sourceFiles);
            /** @var object $analyser the one coverage() gave it */
            $analyser = new ReflectionProperty(CodeCoverage::class, 'analyser')->getValue($appended);
            $lines = self::attributedLines($lines, $attribution, $api, $analyser);
            $functions = self::attributedFunctions($functions, $attribution, $api);
            $tests = $attribution->tests;
        }

        foreach ($views as $viewPath => [$analysisPath, $lineMap]) {
            $lines[$viewPath] = self::mapped($lines[$analysisPath] ?? [], $lineMap);
            $functions[$viewPath] = self::mappedFunctions($functions[$analysisPath] ?? [], $lineMap, $api);
            unset($lines[$analysisPath], $functions[$analysisPath]);
        }

        $data->setLineCoverage($lines);
        $api->setFunctionCoverage($data, $functions);

        $coverage = self::coverage($api, self::reported($sourceFiles, $views), array_map(static fn (array $view): string => $view[0], $views));
        $coverage->setData($data);
        $coverage->setTests($tests);

        return $coverage;
    }

    /** A report of nothing: what the reports are written from when there was nothing to measure. */
    public static function empty(): CodeCoverage
    {
        return self::coverage(CoverageApis::current(), []);
    }

    /**
     * php-code-coverage marks every executable line of a block executed when one line of it ran:
     * such a line was run by the tests of the lines of its block that hold probes.
     *
     * @param array<string, array<int, list<string>|null>> $lines
     *
     * @return array<string, array<int, list<string>|null>>
     */
    private static function attributedLines(array $lines, TestAttribution $attribution, CoverageApi $api, object $analyser): array
    {
        // Every file with tests to name has data: php-code-coverage keeps each file of its filter.
        /** @var non-empty-string $file the path of a loaded source file, or of the analysis copy of a view */
        foreach ($attribution->lines as $file => $attributedLines) {
            $blocks = $api->executableLines($analyser, $file);
            $blockTests = [];

            foreach ($attributedLines as $line => $tests) {
                if (isset($blocks[$line])) {
                    $blockTests[$blocks[$line]] = ($blockTests[$blocks[$line]] ?? []) + array_flip($tests);
                }
            }

            // A line here is never null: sonda's raw data marks each line executed or not, never not executable.
            foreach ($lines[$file] as $line => $tests) {
                if ($tests !== []) {
                    $lines[$file][$line] = array_keys($blockTests[$blocks[$line]]);
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $functions the processed data of each function, by file
     *
     * @return array<string, array<string, mixed>>
     */
    private static function attributedFunctions(array $functions, TestAttribution $attribution, CoverageApi $api): array
    {
        foreach ($functions as $file => $fileFunctions) {
            foreach ($fileFunctions as $name => $function) {
                $branches = $api->branches($function);

                foreach ($branches as $id => $branch) {
                    $branches[$id]['hit'] = $branch['hit'] === [] ? [] : $attribution->branches[$file][$branch['op_start']];
                }

                $functions[$file][$name] = $api->withBranches($function, $branches);
            }
        }

        return $functions;
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
    private static function coverage(CoverageApi $api, array $files, array $copies = []): CodeCoverage
    {
        $filter = new Filter();
        $filter->includeFiles($files);

        $coverage = new CodeCoverage(new CoverageDriver(), $filter);
        $coverage->enableBranchAndPathCoverage();
        // php-code-coverage builds its analyser itself, with these same two settings: the views need their own.
        new ReflectionProperty(CodeCoverage::class, 'analyser')->setValue($coverage, $api->analyser($copies));

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
     * @param array<string, mixed> $functions the processed data of each function
     * @param array<int, int>                              $lineMap
     *
     * @return array<string, mixed>
     */
    private static function mappedFunctions(array $functions, array $lineMap, CoverageApi $api): array
    {
        $mapped = [];

        foreach ($functions as $name => $function) {
            $branches = $api->branches($function);

            foreach ($branches as $id => $branch) {
                $branches[$id]['line_start'] = $lineMap[$branch['line_start']];
                $branches[$id]['line_end'] = $lineMap[$branch['line_end']];
            }

            $mapped[$name] = $api->withBranches($function, $branches);
        }

        return $mapped;
    }
}
