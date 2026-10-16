<?php

declare(strict_types=1);

namespace Sonda\Report;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedFunctionCoverageData;
use SebastianBergmann\CodeCoverage\Node\File;

use function implode, str_starts_with, substr;

/**
 * What a report leaves uncovered, per file: lines never executed and branches never taken,
 * in the whole report or within what a change touched.
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
        return self::ofChanges($coverage, [])->uncovered;
    }

    /**
     * The gaps within what a change touched. In a file with changed lines, only those lines and
     * the methods and functions they fall in are checked; a file mapped to null (a new one) or
     * absent from the changes is checked whole.
     *
     * @param array<string, list<int>|null> $changes changed lines by real path
     */
    public static function ofChanges(CodeCoverage $coverage, array $changes): ChangeCoverage
    {
        $uncovered = [];
        $executableLines = 0;
        $executedLines = 0;
        $executableBranches = 0;
        $executedBranches = 0;

        foreach ($coverage->getReport() as $node) {
            if (! $node instanceof File) {
                continue;
            }

            $file = $node->pathAsString();
            $changedLines = $changes[$file] ?? null;
            $functions = self::countedFunctions($node);
            $checkedLines = null;

            if ($changedLines !== null) {
                $functions = self::touched($functions, $changedLines);
                $checkedLines = self::checkedLines($changedLines, $functions);
            }

            $lines = [];

            foreach ($node->lineCoverageData() as $line => $tests) {
                if ($tests === null || ($checkedLines !== null && ! isset($checkedLines[$line]))) {
                    continue;
                }

                $executableLines++;

                if ($tests === []) {
                    $lines[] = $line;

                    continue;
                }

                $executedLines++;
            }

            $branches = [];
            $branchData = $node->functionCoverageData();

            foreach (array_keys($functions) as $function) {
                $data = $branchData[$function] ?? null;

                // A function never loaded in the run has no branch data: its lines already say so.
                if (! $data instanceof ProcessedFunctionCoverageData) {
                    continue;
                }

                foreach ($data->branches as $branch) {
                    $executableBranches++;

                    if ($branch->hit === []) {
                        $branches[] = [$branch->line_start, $branch->line_end];

                        continue;
                    }

                    $executedBranches++;
                }
            }

            sort($branches);

            if ($lines !== [] || $branches !== []) {
                $uncovered[$file] = ['lines' => $lines, 'branches' => $branches];
            }
        }

        return new ChangeCoverage($uncovered, $executableLines, $executedLines, $executableBranches, $executedBranches);
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
     * The functions the report counts, with their line spans: `Class->method` for the methods of
     * classes and traits, and the functions by name.
     *
     * @return array<string, array{0: int, 1: int}> name => [first line, last line]
     */
    private static function countedFunctions(File $node): array
    {
        $functions = [];

        foreach ($node->functions() as $name => $function) {
            $functions[$name] = [$function->startLine, $function->endLine];
        }

        foreach ([...$node->classes(), ...$node->traits()] as $className => $class) {
            foreach ($class->methods as $methodName => $method) {
                $functions[$className . '->' . $methodName] = [$method->startLine, $method->endLine];
            }
        }

        return $functions;
    }

    /**
     * The functions a changed line falls in.
     *
     * @param array<string, array{0: int, 1: int}> $functions
     * @param list<int>                            $changedLines
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function touched(array $functions, array $changedLines): array
    {
        $touched = [];

        foreach ($functions as $name => [$start, $end]) {
            if (array_any($changedLines, static fn (int $line): bool => $line >= $start && $line <= $end)) {
                $touched[$name] = [$start, $end];
            }
        }

        return $touched;
    }

    /**
     * The changed lines and every line of the functions they touch, as a set.
     *
     * @param list<int>                            $changedLines
     * @param array<string, array{0: int, 1: int}> $functions
     *
     * @return array<int, int> line => line
     */
    private static function checkedLines(array $changedLines, array $functions): array
    {
        $lines = array_flip($changedLines);

        foreach ($functions as [$start, $end]) {
            for ($line = $start; $line <= $end; $line++) {
                $lines[$line] = $line;
            }
        }

        return $lines;
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
