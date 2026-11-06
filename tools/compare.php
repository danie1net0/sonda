<?php

declare(strict_types=1);

// usage: php compare.php <xdebug.cov> <sonda.cov>
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

require getcwd() . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

function short(string $path): string
{
    $workingDirectory = getcwd() . '/';

    return str_starts_with($path, $workingDirectory) ? substr($path, strlen($workingDirectory)) : ltrim($path, '/');
}

function load(string $report): array
{
    $base = '';

    if (class_exists(Unserializer::class)) {
        $data = (new Unserializer())->unserialize($report);
        $base = rtrim($data['basePath'], '/') . '/';
    }

    if (! class_exists(Unserializer::class)) {
        $data = ['codeCoverage' => (include $report)->getData()];
    }

    $lines = $functions = [];

    foreach ($data['codeCoverage']->lineCoverage() as $file => $fileLines) {
        foreach ($fileLines as $line => $tests) {
            if ($tests !== null) {
                $lines[$base . $file][$line] = $tests !== [];
            }
        }
    }

    foreach ($data['codeCoverage']->functionCoverage() as $file => $fileFunctions) {
        $raw = [];

        foreach ($fileFunctions as $name => $function) {
            if (str_starts_with($name, '{') || str_contains($name, 'class@anonymous')) {
                continue;
            }

            $branches = is_array($function) ? $function['branches'] : $function->branches;

            foreach ($branches as $id => $branch) {
                $raw[$name][$id] = is_array($branch)
                    ? [$branch['hit'] !== [], $branch['line_start'], $branch['line_end']]
                    : [$branch->hit !== [], $branch->line_start, $branch->line_end];
            }
        }

        // Xdebug credits a method invoked as a first-class callable ($this->method(...)) to a bare
        // function of the same name, leaving the class-qualified entry with no hits: fold them together.
        foreach (array_keys($raw) as $name) {
            if (str_contains($name, '->') || str_contains($name, '::')) {
                continue;
            }

            $qualifiedNames = array_filter(array_keys($raw), fn ($qualifiedName) => str_ends_with($qualifiedName, '->' . $name) || str_ends_with($qualifiedName, '::' . $name));

            if (count($qualifiedNames) !== 1) {
                continue;
            }

            $qualifiedName = reset($qualifiedNames);

            foreach ($raw[$name] as $id => [$hit]) {
                if (isset($raw[$qualifiedName][$id])) {
                    $raw[$qualifiedName][$id][0] = $raw[$qualifiedName][$id][0] || $hit;
                }
            }

            unset($raw[$name]);
        }

        foreach ($raw as $name => $branches) {
            $missed = [];

            foreach ($branches as [$hit, $lineStart, $lineEnd]) {
                if (! $hit) {
                    $missed[] = [$lineStart, $lineEnd];
                }
            }

            $functions[$base . $file . '::' . $name] = [count($branches) - count($missed), count($branches), $missed, $base . $file];
        }
    }

    $result = [];
    $result[] = $lines;
    $result[] = $functions;

    return $result;
}

[$xdebugLines, $xdebugFunctions] = load($argv[1]);
[$sondaLines, $sondaFunctions] = load($argv[2]);
$verbose = ($argv[3] ?? '') === '-v';

$count = static function (array $lines): array {
    $executable = $covered = 0;

    foreach ($lines as $fileLines) {
        $executable += count($fileLines);
        $covered += count(array_filter($fileLines));
    }

    $result = [];
    $result[] = $covered;
    $result[] = $executable;

    return $result;
};
[$xdebugCovered, $xdebugExecutable] = $count($xdebugLines);
[$sondaCovered, $sondaExecutable] = $count($sondaLines);
printf("LINES   xdebug: %d/%d (%.2f%%)   sonda: %d/%d (%.2f%%)\n", $xdebugCovered, $xdebugExecutable, 100 * $xdebugCovered / $xdebugExecutable, $sondaCovered, $sondaExecutable, 100 * $sondaCovered / $sondaExecutable);

$differences = ['only xdebug executable' => [], 'only sonda executable' => [], 'xdebug hit, sonda miss' => [], 'sonda hit, xdebug miss' => []];

foreach (array_unique([...array_keys($xdebugLines), ...array_keys($sondaLines)]) as $file) {
    $xdebugFileLines = $xdebugLines[$file] ?? [];
    $sondaFileLines = $sondaLines[$file] ?? [];

    foreach (array_keys($xdebugFileLines + $sondaFileLines) as $line) {
        $location = short($file) . ':' . $line;

        if (! isset($sondaFileLines[$line])) {
            $differences['only xdebug executable'][] = $location;

            continue;
        }

        if (! isset($xdebugFileLines[$line])) {
            $differences['only sonda executable'][] = $location;

            continue;
        }

        if ($xdebugFileLines[$line] && ! $sondaFileLines[$line]) {
            $differences['xdebug hit, sonda miss'][] = $location;
        }

        if (! $xdebugFileLines[$line] && $sondaFileLines[$line]) {
            $differences['sonda hit, xdebug miss'][] = $location;
        }
    }
}

foreach ($differences as $kind => $locations) {
    printf("  %-26s %d\n", $kind, count($locations));

    if ($verbose) {
        foreach (array_slice($locations, 0, 15) as $location) {
            echo "      {$location}\n";
        }
    }
}

// branch verdicts per method: is it 100%?
$agreeing = $xdebugOnlyFull = $sondaOnlyFull = $missing = 0;
$xdebugBranchesHit = $xdebugBranches = $sondaBranchesHit = $sondaBranches = 0;
$disagreements = [];

foreach ($xdebugFunctions as $function => [$hits, $total]) {
    $xdebugBranchesHit += $hits;
    $xdebugBranches += $total;

    if (! isset($sondaFunctions[$function])) {
        $missing++;

        continue;
    }

    $xdebugFull = $hits === $total;
    $sondaFull = $sondaFunctions[$function][0] === $sondaFunctions[$function][1];

    if ($xdebugFull === $sondaFull) {
        $agreeing++;

        continue;
    }

    if ($xdebugFull) {
        $xdebugOnlyFull++;
        $disagreements['xdebug 100%, sonda <100%'][] = $function;

        continue;
    }

    $sondaOnlyFull++;
    $disagreements['sonda 100%, xdebug <100%'][] = $function;
}

foreach ($sondaFunctions as [$hits, $total]) {
    $sondaBranchesHit += $hits;
    $sondaBranches += $total;
}

printf("BRANCH  xdebug: %d/%d (%.2f%%)   sonda: %d/%d (%.2f%%)\n", $xdebugBranchesHit, $xdebugBranches, 100 * $xdebugBranchesHit / max(1, $xdebugBranches), $sondaBranchesHit, $sondaBranches, 100 * $sondaBranchesHit / max(1, $sondaBranches));
printf("METHOD 100%%-branch verdict: agree %d, xdebug-only-100%% %d, sonda-only-100%% %d, missing-in-sonda %d\n", $agreeing, $xdebugOnlyFull, $sondaOnlyFull, $missing);
// soundness: a block Xdebug says was missed, in a method sonda reports as 100%, is a sonda blind spot
// (a method sonda already reports as incomplete is not: its missed branch may sit on another line)
$unsound = [];

foreach ($xdebugFunctions as $function => [, , $xdebugMissed, $file]) {
    if (! isset($sondaFunctions[$function]) || $sondaFunctions[$function][2] !== []) {
        continue;
    }

    foreach ($xdebugMissed as [$lineStart, $lineEnd]) {
        [$lineStart, $lineEnd] = [min($lineStart, $lineEnd), max($lineStart, $lineEnd)];
        $found = false;

        foreach ($sondaFunctions[$function][2] ?? [] as [$sondaLineStart, $sondaLineEnd]) {
            if ($sondaLineStart <= $lineEnd && $sondaLineEnd >= $lineStart) {
                $found = true;
                break;
            }
        }

        for ($line = $lineStart; ! $found && $line <= $lineEnd; $line++) {
            if (isset($sondaLines[$file][$line]) && $sondaLines[$file][$line] === false) {
                $found = true;
            }
        }

        if (! $found) {
            $unsound[] = short($function) . " lines {$lineStart}-{$lineEnd}";
        }
    }
}

printf("SOUNDNESS: xdebug-missed blocks not flagged by sonda: %d\n", count($unsound));

foreach (array_slice($unsound, 0, $verbose ? 40 : 10) as $block) {
    echo "      {$block}\n";
}

foreach ($disagreements as $kind => $functions) {
    echo "  {$kind}:\n";

    foreach (array_slice($functions, 0, $verbose ? 40 : 8) as $function) {
        echo '      ', short($function), "\n";
    }
}
