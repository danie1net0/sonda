<?php

declare(strict_types=1);

namespace Sonda\Report;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_starts_with;

final class SourceFiles
{
    /**
     * PHP files under a directory, minus the excluded directories, as sorted real paths.
     *
     * @param list<string> $excludedDirectories
     *
     * @return list<string>
     */
    public static function find(string $directory, array $excludedDirectories = []): array
    {
        $excluded = [];

        foreach ($excludedDirectories as $excludedDirectory) {
            $realExcludedDirectory = realpath($excludedDirectory);

            // A missing directory excludes nothing (an empty path would exclude everything).
            if ($realExcludedDirectory !== false) {
                $excluded[] = $realExcludedDirectory . '/';
            }
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getRealPath();

            if ($file->getExtension() === 'php' && ! self::isExcluded($path, $excluded)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The files of several directories together, each file once.
     *
     * @param list<string> $directories
     * @param list<string> $excludedDirectories
     *
     * @return list<string>
     */
    public static function findAll(array $directories, array $excludedDirectories = []): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $files = [...$files, ...self::find($directory, $excludedDirectories)];
        }

        $files = array_unique($files);
        sort($files);

        return $files;
    }

    /** @param list<string> $excluded */
    private static function isExcluded(string $path, array $excluded): bool
    {
        return array_any($excluded, static fn (string $directory): bool => str_starts_with($path, $directory));
    }
}
