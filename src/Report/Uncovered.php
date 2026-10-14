<?php

declare(strict_types=1);

namespace Sonda\Report;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedFunctionCoverageData;
use SebastianBergmann\CodeCoverage\Node\File;

use function implode, str_starts_with, substr;

/**
 * What a report leaves uncovered, per file: lines never executed and branches never taken.
 *
 * Reads the report tree, so it agrees with the summaries and the HTML: only the branches of
 * the methods and functions declared in the file count, as there.
 *
 * @phpstan-type Gaps array{lines: list<int>, branches: list<array{0: int, 1: int}>}
 */
final class Uncovered
{
    /** @return array<string, Gaps> by file, only the files with gaps */
    public static function of(CodeCoverage $coverage): array
    {
        $gaps = [];

        foreach ($coverage->getReport() as $node) {
            if (! $node instanceof File) {
                continue;
            }

            $file = $node->pathAsString();

            foreach ($node->lineCoverageData() as $line => $tests) {
                if ($tests === []) {
                    $gaps[$file]['lines'][] = $line;
                }
            }

            $branchData = $node->functionCoverageData();

            foreach (self::countedFunctions($node) as $function) {
                $data = $branchData[$function] ?? null;

                // A function never loaded in the run has no branch data: its lines already say so.
                if (! $data instanceof ProcessedFunctionCoverageData) {
                    continue;
                }

                foreach ($data->branches as $branch) {
                    if ($branch->hit === []) {
                        $gaps[$file]['branches'][] = [$branch->line_start, $branch->line_end];
                    }
                }
            }
        }

        $uncovered = [];

        foreach ($gaps as $file => $fileGaps) {
            $branches = $fileGaps['branches'] ?? [];
            sort($branches);

            $uncovered[$file] = ['lines' => $fileGaps['lines'] ?? [], 'branches' => $branches];
        }

        return $uncovered;
    }

    /**
     * One line per file, with the paths relative to the root.
     *
     * @param array<string, Gaps> $uncovered
     */
    public static function describe(array $uncovered, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';
        $lines = [];

        foreach ($uncovered as $file => $gaps) {
            $parts = [];

            if ($gaps['lines'] !== []) {
                $parts[] = 'linhas ' . self::ranges($gaps['lines']);
            }

            if ($gaps['branches'] !== []) {
                $parts[] = 'branches ' . self::list($gaps['branches']);
            }

            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
            $lines[] = $relative . ': ' . implode('; ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * The keys of the branch data that the report counts: `Class->method` and function names.
     *
     * @return list<string>
     */
    private static function countedFunctions(File $node): array
    {
        $functions = array_keys($node->functions());

        foreach ([...$node->classes(), ...$node->traits()] as $className => $class) {
            foreach ($class->methods as $methodName => $method) {
                $functions[] = $className . '->' . $methodName;
            }
        }

        return $functions;
    }

    /**
     * `[3, 4, 5, 9]` reads `3-5, 9`.
     *
     * @param list<int> $numbers sorted
     */
    private static function ranges(array $numbers): string
    {
        $ranges = [];
        $current = null;

        foreach ($numbers as $number) {
            if ($current !== null && $current[1] === $number - 1) {
                $current = [$current[0], $number];

                continue;
            }

            if ($current !== null) {
                $ranges[] = $current;
            }

            $current = [$number, $number];
        }

        if ($current !== null) {
            $ranges[] = $current;
        }

        return self::list($ranges);
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private static function list(array $ranges): string
    {
        return implode(', ', array_map(static fn (array $range): string => self::range($range[0], $range[1]), $ranges));
    }

    private static function range(int $start, int $end): string
    {
        return $start === $end ? (string) $start : "{$start}-{$end}";
    }
}
