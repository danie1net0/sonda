<?php declare(strict_types=1);

namespace CovProto;

final class R
{
    /** @var array<string, array<int, int>> fid => probe id => 1 (current window) */
    public static array $h = [];

    /** @var array<string, array<int, int>> accumulated across tests */
    public static array $acc = [];

    public static bool $perTest = false;

    public static function collected(): array
    {
        return self::$perTest ? self::$acc : self::$h;
    }
}
