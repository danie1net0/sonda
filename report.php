<?php

declare(strict_types=1);

// Usage, from the root of the measured project:
// php report.php <run file> <source directory> <output .cov> [excluded,directories]

use CovProto\Report\ReportWriter;
use CovProto\Run;

require getcwd() . '/vendor/autoload.php';
require __DIR__ . '/autoload.php';

[, $runFile, $sourceDirectory, $outputFile] = $argv;
$excluded = array_values(array_filter(explode(',', $argv[4] ?? '')));

$startedAt = microtime(true);
ReportWriter::write(Run::load($runFile), $sourceDirectory, $excluded, $outputFile);
fprintf(STDERR, "report built in %.2fs\n", microtime(true) - $startedAt);
