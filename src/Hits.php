<?php

declare(strict_types=1);

namespace Sonda;

/**
 * Runtime storage written by the probes of the instrumented code.
 *
 * The instrumented code writes `Hits::$current[<file id>][<probe id>] = 1`, so these
 * properties stay public and static: any other access path would cost more per probe.
 *
 * @phpstan-type TestRecord array{0: string, 1: 'small'|'medium'|'large'|'unknown', 2: bool, 3: float, 4: array<string, string>}
 */
final class Hits
{
    /** @var array<string, array<int, int>> probes hit in the current collection window */
    public static array $current = [];

    /** @var array<string, array<int, int>> probes hit across all finished tests */
    public static array $accumulated = [];

    /** When true, only probes hit inside a test count (the window PHPUnit gives Xdebug). */
    public static bool $perTest = false;

    /**
     * What each finished test hit, for the report to say which tests cover a line.
     *
     * @var array<string, TestRecord> test id => [test file, size, passed, seconds, file id => probe ids packed]
     */
    public static array $tests = [];

    /** @var array<string, true> tests that failed or errored, until they finish */
    public static array $failed = [];

    /** @var array<string, string> each packed list of probe ids once, shared by every test that hit the same probes */
    private static array $packed = [];

    private static int $startedAt = 0;

    public static function startTest(): void
    {
        self::$current = [];
        self::$startedAt = hrtime(true);
    }

    /** Records that a test failed or errored, before it finishes. */
    public static function failTest(string $testId): void
    {
        self::$failed[$testId] = true;
    }

    /**
     * Adds the probes of the test that finished to the accumulated ones and keeps which probes it hit.
     *
     * @param 'small'|'medium'|'large'|'unknown' $size
     */
    public static function finishTest(string $testId, string $testFile, string $size): void
    {
        $packedHits = [];

        foreach (self::$current as $fileId => $hits) {
            self::$accumulated[$fileId] = (self::$accumulated[$fileId] ?? []) + $hits;
            $packed = pack('V*', ...array_keys($hits));
            // Interned: the many tests that hit the same probes of a file share one string.
            $packedHits[$fileId] = self::$packed[$packed] ??= $packed;
        }

        self::$tests[$testId] = [$testFile, $size, ! isset(self::$failed[$testId]), (hrtime(true) - self::$startedAt) / 1e9, $packedHits];
        unset(self::$failed[$testId]);
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
