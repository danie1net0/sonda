<?php

declare(strict_types=1);

namespace Sonda\Report;

use function implode, str_starts_with, substr;

/**
 * The gaps of a report as a GitLab Code Quality report: one issue per run of uncovered lines and
 * per uncovered branch, which the merge request shows on the lines of its diff.
 *
 * @phpstan-import-type Gaps from Uncovered
 */
final class CodeQuality
{
    /**
     * @param array<string, Gaps> $uncovered by file
     * @param string              $root      the repository root, which the paths of the report are relative to
     */
    public static function of(array $uncovered, string $root): string
    {
        $issues = [];

        foreach ($uncovered as $file => $gaps) {
            $path = self::relative($file, $root);

            foreach (Uncovered::runs($gaps['lines']) as [$begin, $end]) {
                $what = $begin === $end ? "Line {$begin} uncovered" : "Lines {$begin}-{$end} uncovered";
                $issues[] = self::issue('sonda-line', $what . ': no test runs it.', $path, $begin, $end);
            }

            foreach ($gaps['branches'] as [$begin, $end]) {
                $issues[] = self::issue('sonda-branch', "Branch at line {$begin} uncovered: some outcome of this decision was never tested.", $path, $begin, $end);
            }
        }

        return json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array{type: string, check_name: string, description: string, categories: list<string>, severity: string, fingerprint: string, location: array{path: string, lines: array{begin: int, end: int}}} */
    private static function issue(string $check, string $description, string $path, int $begin, int $end): array
    {
        $lines = [];
        $lines['begin'] = $begin;
        $lines['end'] = $end;

        $location = [];
        $location['path'] = $path;
        $location['lines'] = $lines;

        $issue = [];
        $issue['type'] = 'issue';
        $issue['check_name'] = $check;
        $issue['description'] = $description;
        $issue['categories'] = ['Bug Risk'];
        $issue['severity'] = 'minor';
        // Stable while the gap stays where it is: GitLab tells new issues from old ones by it.
        $issue['fingerprint'] = md5(implode(':', [$check, $path, $begin, $end]));
        $issue['location'] = $location;

        return $issue;
    }

    private static function relative(string $file, string $root): string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
