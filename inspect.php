<?php
// usage: php inspect.php <xd.cov> <proto.cov> <file-suffix> <line-from> <line-to>
require getcwd() . '/vendor/autoload.php';
require __DIR__ . '/src/ProtoDriver.php';
[, $x, $p, $suffix, $from, $to] = $argv;
foreach (['xdebug' => $x, 'proto' => $p] as $label => $f) {
    $d = class_exists(SebastianBergmann\CodeCoverage\Serialization\Unserializer::class) ? (new SebastianBergmann\CodeCoverage\Serialization\Unserializer)->unserialize($f)['codeCoverage'] : (include $f)->getData();
    foreach ($d->lineCoverage() as $file => $ls) {
        if (!str_ends_with($file, $suffix)) continue;
        echo "$label lines: ";
        for ($l = $from; $l <= $to; $l++) echo $l, '=', array_key_exists($l, $ls) ? ($ls[$l] === null ? 'dead' : count($ls[$l])) : '-', ' ';
        echo "\n";
    }
    foreach ($d->functionCoverage() as $file => $fns) {
        if (!str_ends_with($file, $suffix)) continue;
        foreach ($fns as $name => $fn) {
            $branches = is_array($fn) ? $fn['branches'] : $fn->branches;
            foreach ($branches as $id => $b) {
                $b = is_array($b) ? $b : (array) $b;
                if (max($b['line_start'], $b['line_end']) >= $from && min($b['line_start'], $b['line_end']) <= $to) {
                    echo "  $label $name br$id {$b['line_start']}-{$b['line_end']} hit=", count($b['hit']), "\n";
                }
            }
        }
    }
}
