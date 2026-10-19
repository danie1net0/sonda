<?php

declare(strict_types=1);

namespace Sonda\Git;

use function trim;

/**
 * Every file changed since a reference, of any kind: committed since the merge base, modified,
 * deleted or new in the working tree. A rename counts as the deletion of the old path and the
 * creation of the new one.
 */
final class ChangedPaths
{
    /** @return list<string> absolute paths, under the top level of the repository, sorted */
    public static function since(string $repository, string $reference): array
    {
        // Both listings name paths from the top level, which a project in a subdirectory of the repository is not.
        $root = trim(Git::run($repository, ['rev-parse', '--show-toplevel']));
        $listing = Git::run($repository, ['diff', '--name-only', '--no-renames', Git::base($repository, $reference)])
            . Git::run($repository, ['ls-files', '--others', '--exclude-standard', '--full-name']);
        $paths = [];

        foreach (explode("\n", $listing) as $relative) {
            if ($relative !== '') {
                $paths[$relative] = $root . '/' . $relative;
            }
        }

        ksort($paths);

        return array_values($paths);
    }
}
