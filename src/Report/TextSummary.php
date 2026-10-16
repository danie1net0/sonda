<?php

declare(strict_types=1);

namespace Sonda\Report;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File;

use function implode, str_starts_with, substr;

/** The coverage of each file of a report, one line each, as the summary counts it. */
final class TextSummary
{
    public static function of(CodeCoverage $coverage, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';
        $lines = [];

        foreach ($coverage->getReport() as $node) {
            if (! $node instanceof File) {
                continue;
            }

            $path = $node->pathAsString();
            $lines[] = sprintf(
                '%s: linhas %d/%d (%s), branches %d/%d (%s)',
                str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                $node->numberOfExecutedLines(),
                $node->numberOfExecutableLines(),
                self::percentage($node->numberOfExecutedLines(), $node->numberOfExecutableLines()),
                $node->numberOfExecutedBranches(),
                $node->numberOfExecutableBranches(),
                self::percentage($node->numberOfExecutedBranches(), $node->numberOfExecutableBranches()),
            );
        }

        // php-code-coverage keeps its files in path order: the lines come out sorted.
        return implode("\n", $lines);
    }

    /** `97,50%`; nothing to cover counts as all covered. */
    public static function percentage(int $covered, int $total): string
    {
        return self::format(self::ratio($covered, $total));
    }

    public static function ratio(int $covered, int $total): float
    {
        return $total === 0 ? 100.0 : $covered * 100 / $total;
    }

    public static function format(float $percentage): string
    {
        return number_format($percentage, 2, ',', '.') . '%';
    }
}
