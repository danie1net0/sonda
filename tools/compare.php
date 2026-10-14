<?php

declare(strict_types=1);

// usage: php compare.php <xdebug.cov> <sonda.cov>
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

require getcwd() . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

function load(string $f): array
{
    if (class_exists(Unserializer::class)) {
        $d = (new Unserializer())->unserialize($f);
        $base = rtrim($d['basePath'], '/') . '/';
    } else {
        $d = ['codeCoverage' => (include $f)->getData()];
        $base = '';
    }
    $lines = $fns = [];

    foreach ($d['codeCoverage']->lineCoverage() as $file => $ls) {
        foreach ($ls as $line => $tests) {
            if ($tests !== null) {
                $lines[$base . $file][$line] = $tests !== [];
            }
        }
    }

    foreach ($d['codeCoverage']->functionCoverage() as $file => $functions) {
        foreach ($functions as $name => $data) {
            if (str_starts_with($name, '{') || str_contains($name, 'class@anonymous')) {
                continue;
            }
            $branches = is_array($data) ? $data['branches'] : $data->branches;
            $total = count($branches);
            $hit = count(array_filter($branches, fn ($b) => (is_array($b) ? $b['hit'] : $b->hit) !== []));
            $miss = [];

            foreach ($branches as $b) {
                $bh = is_array($b) ? $b['hit'] : $b->hit;

                if ($bh === []) {
                    $miss[] = is_array($b) ? [$b['line_start'], $b['line_end']] : [$b->line_start, $b->line_end];
                }
            }
            $fns[$base . $file . '::' . $name] = [$hit, $total, $miss, $base . $file];
        }
    }

    return [$lines, $fns];
}

[$xl, $xf] = load($argv[1]);
[$pl, $pf] = load($argv[2]);
$verbose = ($argv[3] ?? '') === '-v';

$count = static function (array $lines): array {
    $e = $c = 0;

    foreach ($lines as $ls) {
        $e += count($ls);
        $c += count(array_filter($ls));
    }

    return [$c, $e];
};
[$xc, $xe] = $count($xl);
[$pc, $pe] = $count($pl);
printf("LINES   xdebug: %d/%d (%.2f%%)   sonda: %d/%d (%.2f%%)\n", $xc, $xe, 100 * $xc / $xe, $pc, $pe, 100 * $pc / $pe);

$diff = ['only xdebug executable' => [], 'only sonda executable' => [], 'xdebug hit, sonda miss' => [], 'sonda hit, xdebug miss' => []];

foreach (array_unique([...array_keys($xl), ...array_keys($pl)]) as $file) {
    $x = $xl[$file] ?? [];
    $p = $pl[$file] ?? [];

    foreach ($x + $p as $line => $_) {
        $short = substr($file, strpos($file, '/src/') + 1) . ':' . $line;

        if (! isset($p[$line])) {
            $diff['only xdebug executable'][] = $short;

            continue;
        }

        if (! isset($x[$line])) {
            $diff['only sonda executable'][] = $short;

            continue;
        }

        if ($x[$line] && ! $p[$line]) {
            $diff['xdebug hit, sonda miss'][] = $short;
        }

        if (! $x[$line] && $p[$line]) {
            $diff['sonda hit, xdebug miss'][] = $short;
        }
    }
}

foreach ($diff as $k => $v) {
    printf("  %-26s %d\n", $k, count($v));

    if ($verbose) {
        foreach (array_slice($v, 0, 15) as $s) {
            echo "      {$s}\n";
        }
    }
}

// branch verdicts per method: is it 100%?
$agree = $xOnlyFull = $pOnlyFull = $missing = 0;
$xbh = $xbt = $pbh = $pbt = 0;
$list = [];

foreach ($xf as $k => [$h, $t, $xmiss, $xfile]) {
    $xbh += $h;
    $xbt += $t;

    if (! isset($pf[$k])) {
        $missing++;

        continue;
    }
    $xFull = $h === $t;
    $pFull = $pf[$k][0] === $pf[$k][1];

    if ($xFull === $pFull) {
        $agree++;
    } elseif ($xFull) {
        $pOnlyFull += 0;
        $xOnlyFull++;
        $list['xdebug 100%, sonda <100%'][] = $k;
    } else {
        $pOnlyFull++;
        $list['sonda 100%, xdebug <100%'][] = $k;
    }
}

foreach ($pf as [$h, $t]) {
    $pbh += $h;
    $pbt += $t;
}
printf("BRANCH  xdebug: %d/%d (%.2f%%)   sonda: %d/%d (%.2f%%)\n", $xbh, $xbt, 100 * $xbh / max(1, $xbt), $pbh, $pbt, 100 * $pbh / max(1, $pbt));
printf("METHOD 100%%-branch verdict: agree %d, xdebug-only-100%% %d, sonda-only-100%% %d, missing-in-sonda %d\n", $agree, $xOnlyFull, $pOnlyFull, $missing);
// soundness: every block Xdebug says was missed must show up in sonda as a missed branch or missed line
$unsound = [];

foreach ($xf as $k => [$h, $t, $xmiss, $file]) {
    foreach ($xmiss as [$ls, $le]) {
        [$ls, $le] = [min($ls, $le), max($ls, $le)];
        $found = false;

        foreach ($pf[$k][2] ?? [] as [$pls, $ple]) {
            if ($pls <= $le && $ple >= $ls) {
                $found = true;
                break;
            }
        }

        for ($l = $ls; ! $found && $l <= $le; $l++) {
            if (isset($pl[$file][$l]) && $pl[$file][$l] === false) {
                $found = true;
            }
        }

        if (! $found) {
            $unsound[] = substr($k, strpos($k, '/src/') + 1) . " lines {$ls}-{$le}";
        }
    }
}
printf("SOUNDNESS: xdebug-missed blocks not flagged by sonda: %d\n", count($unsound));

foreach (array_slice($unsound, 0, $verbose ? 40 : 10) as $u) {
    echo "      {$u}\n";
}

foreach ($list as $k => $v) {
    echo "  {$k}:\n";

    foreach (array_slice($v, 0, $verbose ? 40 : 8) as $s) {
        echo "      ", substr($s, strpos($s, '/src/') + 1), "\n";
    }
}
