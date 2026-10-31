<?php

declare(strict_types=1);

namespace Sonda\Cli;

/** The version of the sonda that is running: the one Composer installed, or the tag of a checkout. */
final readonly class Version
{
    public static function of(string $sondaRoot): string
    {
        // Installed as a dependency, the root is vendor/ddr/sonda and Composer keeps the version
        // beside it, in vendor/composer/installed.php.
        $installed = dirname($sondaRoot, 2) . '/composer/installed.php';

        if (is_file($installed)) {
            /** @var array{versions?: array<string, array{pretty_version?: string}>} $packages */
            $packages = require $installed;
            $version = $packages['versions']['ddr/sonda']['pretty_version'] ?? null;

            if (is_string($version)) {
                return $version;
            }
        }

        return self::describe($sondaRoot) ?? 'unknown';
    }

    /** The nearest tag of a checkout, with the commits past it; the commit alone before any tag. */
    private static function describe(string $sondaRoot): ?string
    {
        // Outside a repository git says nothing here: its complaint goes to the discarded stderr.
        $described = (string) exec('git -C ' . escapeshellarg($sondaRoot) . ' describe --tags --always 2>/dev/null');

        if ($described === '') {
            return null;
        }

        return ltrim($described, 'v');
    }
}
