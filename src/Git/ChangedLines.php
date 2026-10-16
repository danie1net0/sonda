<?php

declare(strict_types=1);

namespace Sonda\Git;

use function str_ends_with, str_starts_with, substr, trim;

/**
 * The PHP files changed since a reference and, in each, the lines the change added or replaced:
 * committed since the merge base, modified or new in the working tree. A new file maps to null,
 * which stands for every line of it.
 */
final class ChangedLines
{
    /** @return array<string, list<int>|null> real path => changed lines, sorted by path */
    public static function since(string $repository, string $reference): array
    {
        $base = trim(Git::run($repository, ['merge-base', $reference, 'HEAD']));
        $hunks = self::addedLinesByFile(Git::run($repository, ['diff', '--unified=0', $base]));
        $changes = [];

        foreach (self::phpFiles($repository, Git::run($repository, ['diff', '--name-only', $base])) as $relative => $path) {
            $changes[$path] = $hunks[$relative] ?? [];
        }

        foreach (self::phpFiles($repository, Git::run($repository, ['ls-files', '--others', '--exclude-standard'])) as $path) {
            $changes[$path] = null;
        }

        ksort($changes);

        return $changes;
    }

    /**
     * The PHP files of a listing that still exist, by their path in the listing.
     *
     * @return array<string, string> relative path => real path
     */
    private static function phpFiles(string $repository, string $listing): array
    {
        $files = [];

        foreach (explode("\n", $listing) as $relative) {
            $path = realpath($repository . '/' . $relative);

            // A deleted file has no real path; the empty last line resolves to the repository itself.
            if ($path !== false && str_ends_with($path, '.php')) {
                $files[$relative] = $path;
            }
        }

        return $files;
    }

    /**
     * The lines of the new version that a diff without context added or replaced, by file: the
     * `+++ b/<path>` header names the file, and each `@@ -a,b +c,d @@` hunk header adds `d` lines
     * from `c`, one when `d` is absent, none when it is zero. The diff covers the whole tree so
     * that a renamed file keeps only the lines its content changed.
     *
     * @return array<string, list<int>> relative path => lines
     */
    private static function addedLinesByFile(string $diff): array
    {
        $lines = [];
        $file = null;

        foreach (explode("\n", $diff) as $diffLine) {
            if (str_starts_with($diffLine, '+++ ')) {
                $file = str_starts_with($diffLine, '+++ b/') ? substr($diffLine, 6) : null;

                continue;
            }

            if ($file === null || preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $diffLine, $hunk) !== 1) {
                continue;
            }

            $start = (int) $hunk[1];
            $count = ($hunk[2] ?? '') === '' ? 1 : (int) $hunk[2];

            for ($line = $start; $line < $start + $count; $line++) {
                $lines[$file][] = $line;
            }
        }

        return $lines;
    }
}
