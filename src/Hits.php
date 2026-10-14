<?php

declare(strict_types=1);

namespace Sonda;

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

    /**
     * Probes the left side of `?->` and passes it through: `$order?->total()` becomes
     * `Hits::nullsafe('file', 4, 5, $order)?->total()`. The null probe is null unless both
     * outcomes count (strict mode).
     */
    public static function nullsafe(string $fileId, int $nonNullProbe, ?int $nullProbe, mixed $value): mixed
    {
        if ($value !== null) {
            self::$current[$fileId][$nonNullProbe] = 1;

            return $value;
        }

        if ($nullProbe !== null) {
            self::$current[$fileId][$nullProbe] = 1;
        }

        return null;
    }

    /**
     * Probes the left operand of `&&`, `||`, `and` and `or` deciding alone and passes it
     * through: `$a && $b` becomes `Hits::shortCircuit('file', 4, false, $a) && $b`. The
     * probe is hit when the operand's truthiness is the one that skips the right operand.
     */
    public static function shortCircuit(string $fileId, int $probe, bool $decidesWhenTruthy, mixed $value): mixed
    {
        if ((bool) $value === $decidesWhenTruthy) {
            self::$current[$fileId][$probe] = 1;
        }

        return $value;
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
