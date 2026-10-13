<?php

declare(strict_types=1);

namespace CovProto;

/**
 * Runtime storage written by the probes of the instrumented code.
 *
 * The instrumented code writes `Hits::$current[<file id>][<probe id>] = 1`, so these
 * properties stay public and static: any other access path would cost more per probe.
 */
final class Hits
{
    /** @var array<string, array<int, int>> probes hit in the current collection window */
    public static array $current = [];

    /** @var array<string, array<int, int>> probes hit across all finished tests */
    public static array $accumulated = [];

    /** When true, only probes hit inside a test count (the window PHPUnit gives Xdebug). */
    public static bool $perTest = false;

    public static function startTest(): void
    {
        self::$current = [];
    }

    public static function finishTest(): void
    {
        foreach (self::$current as $fileId => $hits) {
            self::$accumulated[$fileId] = (self::$accumulated[$fileId] ?? []) + $hits;
        }

        self::$current = [];
    }

    /** @return array<string, array<int, int>> */
    public static function collected(): array
    {
        if (self::$perTest) {
            return self::$accumulated;
        }

        return self::$current;
    }
}
