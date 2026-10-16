<?php

declare(strict_types=1);

namespace Sonda;

use function dirname;

/**
 * The files of Composer's `files` autoload (helpers, mostly) that are in scope: claimed as
 * already loaded before Composer's autoloader runs, so it skips them, and loaded from their
 * instrumented copies right after, in Composer's order.
 */
final class ComposerFiles
{
    /**
     * @param string $autoloadPath the project's vendor/autoload.php
     *
     * @return list<string> the real paths claimed
     */
    public static function claim(string $autoloadPath, Scope $scope): array
    {
        /** @var array<int|string, true> $loaded what Composer takes as already required */
        $loaded = $GLOBALS['__composer_autoload_files'] ?? [];
        $claimed = [];

        foreach (self::listed($autoloadPath) as $identifier => $path) {
            if ($scope->includes($path)) {
                $loaded[$identifier] = true;
                $claimed[] = $path;
            }
        }

        $GLOBALS['__composer_autoload_files'] = $loaded;

        return $claimed;
    }

    /**
     * The files of Composer's `files` autoload that exist, by their identifier.
     *
     * @param string $autoloadPath the project's vendor/autoload.php
     *
     * @return array<int|string, string> identifier => real path
     */
    public static function listed(string $autoloadPath): array
    {
        $listing = dirname($autoloadPath) . '/composer/autoload_files.php';

        if (! is_file($listing)) {
            return [];
        }

        $files = require $listing;
        $listed = [];

        foreach (is_array($files) ? $files : [] as $identifier => $file) {
            $path = is_string($file) ? realpath($file) : false;

            if ($path !== false) {
                $listed[$identifier] = $path;
            }
        }

        return $listed;
    }
}
