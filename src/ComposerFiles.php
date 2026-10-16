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
        $listing = dirname($autoloadPath) . '/composer/autoload_files.php';

        if (! is_file($listing)) {
            return [];
        }

        $files = require $listing;
        /** @var array<int|string, true> $loaded what Composer takes as already required */
        $loaded = $GLOBALS['__composer_autoload_files'] ?? [];
        $claimed = [];

        foreach (is_array($files) ? $files : [] as $identifier => $file) {
            $path = is_string($file) ? realpath($file) : false;

            if ($path !== false && $scope->includes($path)) {
                $loaded[$identifier] = true;
                $claimed[] = $path;
            }
        }

        $GLOBALS['__composer_autoload_files'] = $loaded;

        return $claimed;
    }
}
