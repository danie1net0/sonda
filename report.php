<?php

declare(strict_types=1);

// Usage, from the root of the measured project:
// php report.php <directory of the run files> <source directory> <output .cov> [excluded,directories]

use Sonda\Report\ReportWriter;
use Sonda\Run;

require getcwd() . '/vendor/autoload.php';
require __DIR__ . '/autoload.php';

[, $runDirectory, $sourceDirectory, $outputFile] = $argv;
$excluded = array_values(array_filter(explode(',', $argv[4] ?? '')));

$startedAt = microtime(true);
ReportWriter::write(Run::loadDirectory($runDirectory), $sourceDirectory, $excluded, $outputFile);
fprintf(STDERR, "report built in %.2fs\n", microtime(true) - $startedAt);
