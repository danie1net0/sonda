<?php

declare(strict_types=1);

namespace Sonda;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Filesystem
{
    public static function read(string $path): string
    {
        // Reading a missing path would warn: the check keeps the failure to the exception.
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("The file {$path} could not be read.");
        }

        return $contents;
    }

    /**
     * Creates a directory only its owner can enter, or checks that an existing one is so. The
     * instrumented copies kept there run as code, so nobody else may write to it.
     */
    public static function privateDirectory(string $directory): void
    {
        self::ensureDirectory($directory);

        $info = new SplFileInfo($directory);

        if ($info->getOwner() !== getmyuid() || ($info->getPerms() & 0o077) !== 0) {
            throw new RuntimeException("The directory {$directory} must belong to the current user and be private: chmod 700 on it fixes that.");
        }
    }

    /** Creates a directory with its parents when it is missing; an existing one is left as it is. */
    public static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            // Parallel test workers may create it at the same time: the loser's warning is noise.
            set_error_handler(static fn (): bool => true);

            try {
                mkdir($directory, 0o700, true);
            } finally {
                restore_error_handler();
            }
        }

        if (! is_dir($directory)) {
            throw new RuntimeException("The directory {$directory} could not be created.");
        }
    }

    /** Removes a directory with everything in it; a missing one is fine. */
    public static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
