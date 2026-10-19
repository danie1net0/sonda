<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\{Probe, Run};
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

use function max;

/**
 * Turns the probes of a run into the raw data php-code-coverage builds its reports from,
 * in the shape Xdebug reports it.
 *
 * @phpstan-type Branch array{op_start: int, op_end: int, line_start: int, line_end: int, hit: int, out: array<int, int>, out_hit: array<int, int>}
 * @phpstan-type FunctionCoverage array{branches: array<int, Branch>, paths: array<int, array{path: array<int, int>, hit: int}>}
 * @phpstan-type FileCoverage array{lines: array<int, int>, functions: array<string, FunctionCoverage>}
 */
final class RawCoverageBuilder
{
    /** @param list<string> $sourceFiles only these files enter the report */
    public static function build(Run $run, array $sourceFiles): RawCodeCoverageData
    {
        $coverage = [];

        foreach (self::keysOf($run, $sourceFiles) as $fileId => $key) {
            $coverage[$key] = self::fileCoverage($run->probesOf($run->files[$fileId][1]), $run->hits[$fileId] ?? []);
        }

        return RawCodeCoverageData::fromXdebugWithPathCoverage($coverage);
    }

    /**
     * The key each loaded source file has in the coverage data: its path, or for a view, whose
     * probes refer to its compiled code, the analysis copy of it.
     *
     * @param list<string> $sourceFiles
     *
     * @return array<string, string> file id => key
     */
    public static function keysOf(Run $run, array $sourceFiles): array
    {
        $isSource = array_flip($sourceFiles);
        $keys = [];

        foreach ($run->files as $fileId => [$path, $cacheKey]) {
            if (isset($isSource[$path])) {
                $keys[$fileId] = $run->lineMapOf($cacheKey) === null ? $path : $run->analysisPathOf($cacheKey);
            }
        }

        return $keys;
    }

    /**
     * @param array<int, Probe> $probes
     * @param array<int, int>   $hits
     *
     * @return FileCoverage
     */
    private static function fileCoverage(array $probes, array $hits): array
    {
        $lines = [];
        $functions = [];

        foreach ($probes as $id => $probe) {
            $status = isset($hits[$id]) ? Driver::LINE_EXECUTED : Driver::LINE_NOT_EXECUTED;

            foreach ($probe->lines() as $line) {
                $lines[$line] = max($lines[$line] ?? Driver::LINE_NOT_EXECUTED, $status);
            }

            if ($probe->countsAsBranch()) {
                $functions[$probe->functionName]['branches'][] = self::branch($id, $probe, isset($hits[$id]));
                $functions[$probe->functionName]['paths'] = [];
            }
        }

        $coverage = [];
        $coverage['lines'] = $lines;
        $coverage['functions'] = $functions;

        return $coverage;
    }

    /** @return Branch */
    private static function branch(int $id, Probe $probe, bool $isHit): array
    {
        $branch = [];
        $branch['op_start'] = $id;
        $branch['op_end'] = $id;
        $branch['line_start'] = $probe->lineStart;
        $branch['line_end'] = $probe->lineEnd;
        $branch['hit'] = $isHit ? Driver::BRANCH_HIT : Driver::BRANCH_NOT_HIT;
        $branch['out'] = [];
        $branch['out_hit'] = [];

        return $branch;
    }
}
