<?php

declare(strict_types=1);

namespace Sonda\Git;

use function str_ends_with, trim;

/** The PHP files changed since a reference: committed since the merge base, modified or new in the working tree. */
final class ChangedFiles
{
    /** @return list<string> real paths, sorted */
    public static function since(string $repository, string $reference): array
    {
        $base = trim(Git::run($repository, ['merge-base', $reference, 'HEAD']));
        $listed = [
            ...explode("\n", Git::run($repository, ['diff', '--name-only', $base])),
            ...explode("\n", Git::run($repository, ['ls-files', '--others', '--exclude-standard'])),
        ];
        $files = [];

        foreach ($listed as $relative) {
            $path = realpath($repository . '/' . $relative);

            // A deleted file has no real path; the empty last line resolves to the repository itself.
            if ($path !== false && str_ends_with($path, '.php')) {
                $files[$path] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
