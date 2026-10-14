<?php

declare(strict_types=1);

// Usage, from the root of the measured project:
// php report.php <directory of the run files> <source directory> <output .cov> [excluded directory]...

use Sonda\Report\ReportCommand;

require getcwd() . '/vendor/autoload.php';
require __DIR__ . '/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
$strings = is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : [];

try {
    fwrite(STDERR, ReportCommand::run(array_slice($strings, 1)) . "\n");
} catch (InvalidArgumentException $invalidArgumentException) {
    fwrite(STDERR, $invalidArgumentException->getMessage() . "\n");

    exit(1);
}
