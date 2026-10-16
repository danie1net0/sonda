<?php

declare(strict_types=1);

namespace Sonda\Project;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function in_array;

/** The `resources/views` folders of a Laravel project: its own and those of the packages and modules kept in it. */
final class ViewFolders
{
    /** Top-level folders that hold no code of the project. */
    private const array SKIPPED = ['.git', 'bootstrap', 'node_modules', 'public', 'storage', 'tests', 'vendor'];

    /** `Modules/Blog/resources/views` is as deep as it goes. */
    private const int DEPTH = 3;

    /** @return list<string> sorted real paths */
    public static function in(string $projectRoot): array
    {
        $directories = new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator($directories, static fn (SplFileInfo $entry, string $key, RecursiveDirectoryIterator $iterator): bool => self::enters($entry, $iterator)), RecursiveIteratorIterator::SELF_FIRST);
        $iterator->setMaxDepth(self::DEPTH);

        $folders = [];

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->getFilename() === 'views' && basename($entry->getPath()) === 'resources') {
                $folders[] = (string) $entry->getRealPath();
            }
        }

        sort($folders);

        return $folders;
    }

    private static function enters(SplFileInfo $entry, RecursiveDirectoryIterator $directories): bool
    {
        return $entry->isDir() && ($directories->getSubPath() !== '' || ! in_array($entry->getFilename(), self::SKIPPED, true));
    }
}
