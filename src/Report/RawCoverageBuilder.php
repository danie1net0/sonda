<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Probe;
use Sonda\Run;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

use function max;

/** Turns the probes of a run into the raw data php-code-coverage builds its reports from. */
final class RawCoverageBuilder
{
    /** @param list<string> $sourceFiles only these files enter the report */
    public static function build(Run $run, array $sourceFiles): RawCodeCoverageData
    {
        $isSource = array_flip($sourceFiles);
        $coverage = [];

        foreach ($run->files as $fileId => [$path, $cacheKey]) {
            if (!isset($isSource[$path])) {
                continue;
            }

            $coverage[$path] = self::fileCoverage($run->probesOf($cacheKey), $run->hits[$fileId] ?? []);
        }

        return RawCodeCoverageData::fromXdebugWithPathCoverage($coverage);
    }

    /**
     * @param array<int, Probe> $probes
     * @param array<int, int>   $hits
     *
     * @return array{lines: array<int, int>, functions: array<string, array{branches: list<array<string, mixed>>, paths: list<mixed>}>}
     */
    private static function fileCoverage(array $probes, array $hits): array
    {
        $lines     = [];
        $functions = [];

        foreach ($probes as $id => $probe) {
            $status = isset($hits[$id]) ? Driver::LINE_EXECUTED : Driver::LINE_NOT_EXECUTED;

            foreach ($probe->lines() as $line) {
                $lines[$line] = max($lines[$line] ?? Driver::LINE_NOT_EXECUTED, $status);
            }

            if ($probe->countsAsBranch()) {
                $functions[$probe->functionName]['branches'][] = self::branch($id, $probe, isset($hits[$id]));
                $functions[$probe->functionName]['paths']      = [];
            }
        }

        $coverage              = [];
        $coverage['lines']     = $lines;
        $coverage['functions'] = $functions;

        return $coverage;
    }

    /** @return array<string, mixed> in the shape Xdebug reports a branch */
    private static function branch(int $id, Probe $probe, bool $isHit): array
    {
        $branch               = [];
        $branch['op_start']   = $id;
        $branch['op_end']     = $id;
        $branch['line_start'] = $probe->lineStart;
        $branch['line_end']   = $probe->lineEnd;
        $branch['hit']        = $isHit ? Driver::BRANCH_HIT : Driver::BRANCH_NOT_HIT;
        $branch['out']        = [];
        $branch['out_hit']    = [];

        return $branch;
    }
}
