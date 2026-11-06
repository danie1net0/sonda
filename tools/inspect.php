<?php

// usage: php inspect.php <xd.cov> <sonda.cov> <file-suffix> <line-from> <line-to>
require getcwd() . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';
[, $xdebugReport, $sondaReport, $suffix, $from, $to] = $argv;

foreach (['xdebug' => $xdebugReport, 'sonda' => $sondaReport] as $label => $report) {
    $data = class_exists(SebastianBergmann\CodeCoverage\Serialization\Unserializer::class) ? (new SebastianBergmann\CodeCoverage\Serialization\Unserializer())->unserialize($report)['codeCoverage'] : (include $report)->getData();

    foreach ($data->lineCoverage() as $file => $lines) {
        if (! str_ends_with($file, $suffix)) {
            continue;
        }

        echo "{$label} lines: ";

        for ($line = $from; $line <= $to; $line++) {
            echo $line, '=', array_key_exists($line, $lines) ? ($lines[$line] === null ? 'dead' : count($lines[$line])) : '-', ' ';
        }

        echo "\n";
    }

    foreach ($data->functionCoverage() as $file => $functions) {
        if (! str_ends_with($file, $suffix)) {
            continue;
        }

        foreach ($functions as $name => $function) {
            $branches = is_array($function) ? $function['branches'] : $function->branches;

            foreach ($branches as $id => $branch) {
                $branch = is_array($branch) ? $branch : (array) $branch;

                if (max($branch['line_start'], $branch['line_end']) >= $from && min($branch['line_start'], $branch['line_end']) <= $to) {
                    echo "  {$label} {$name} br{$id} {$branch['line_start']}-{$branch['line_end']} hit=", count($branch['hit']), "\n";
                }
            }
        }
    }
}
