<?php declare(strict_types=1);

// usage: php report.php <hits.ser> <srcDir> <out.cov>
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

require getcwd() . '/vendor/autoload.php';

[, $hitsFile, $srcDir, $outFile] = $argv;
$excludes = array_filter(array_map(fn ($d) => realpath($d), explode(',', $argv[4] ?? '')));

require __DIR__ . '/src/ProtoDriver.php';

$t0   = microtime(true);
$run  = unserialize(file_get_contents($hitsFile));
$lines = [];
$functions = [];

foreach ($run['files'] as $fid => [$file, $key]) {
    $probes = unserialize(file_get_contents($run['cache'] . '/' . $key . '.map'));
    $hits   = $run['hits'][$fid] ?? [];
    $lines[$file] = [];
    $functions[$file] = [];

    foreach ($probes as $id => $probe) {
        [$line, $isBranch, $fn, $ls, $le] = $probe;
        $hit = isset($hits[$id]);

        foreach ([...($line !== null ? [$line] : []), ...($probe[5] ?? [])] as $l) {
            $lines[$file][$l] = max($lines[$file][$l] ?? Driver::LINE_NOT_EXECUTED, $hit ? Driver::LINE_EXECUTED : Driver::LINE_NOT_EXECUTED);
        }

        if ($isBranch && !str_starts_with($fn, '{')) {
            $n = count($functions[$file][$fn]['branches'] ?? []);
            $functions[$file][$fn]['branches'][$n] = [
                'op_start' => $id, 'op_end' => $id, 'line_start' => $ls, 'line_end' => $le,
                'hit' => $hit ? 1 : 0, 'out' => [], 'out_hit' => [],
            ];
            $functions[$file][$fn]['paths'] ??= [];
        }
    }
}

$filter = new Filter;
$files = array_values(array_filter(array_map('realpath', glob_recursive(realpath($srcDir))), static function (string $f) use ($excludes): bool {
    foreach ($excludes as $e) {
        if (str_starts_with($f, $e . '/')) {
            return false;
        }
    }
    return true;
}));
$filter->includeFiles($files);
$lines = array_intersect_key($lines, array_flip($files));
$functions = array_intersect_key($functions, array_flip($files));

$cc = new CodeCoverage(new ProtoDriver, $filter);
$cc->enableBranchAndPathCoverage();
$raw = [];
foreach ($lines as $file => $ls) {
    $raw[$file] = ['lines' => $ls, 'functions' => $functions[$file] ?? []];
}
$cc->append(RawCodeCoverageData::fromXdebugWithPathCoverage($raw), 'proto');
if (class_exists(Serializer::class)) {
    (new Serializer)->serialize($outFile, $cc);
} else {
    (new SebastianBergmann\CodeCoverage\Report\PHP)->process($cc, $outFile);
}
fprintf(STDERR, "report built in %.2fs\n", microtime(true) - $t0);

function glob_recursive(string $dir): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    return $files;
}
