<?php

declare(strict_types=1);

namespace Sonda\Cli;

/** Where sonda keeps the caches and runs of every project: a directory of the current user's own. */
final class WorkDirectory
{
    /** @param array<string, string> $environment */
    public static function default(array $environment): string
    {
        $cacheHome = $environment['XDG_CACHE_HOME'] ?? '';

        if ($cacheHome !== '') {
            return $cacheHome . '/sonda';
        }

        $home = $environment['HOME'] ?? '';

        if ($home !== '') {
            return $home . '/.cache/sonda';
        }

        return sys_get_temp_dir() . '/sonda-' . getmyuid();
    }
}
