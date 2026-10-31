<?php

declare(strict_types=1);

namespace Sonda\Report;

use InvalidArgumentException;
use Sonda\Run;

/** `report.php <run directory> <source directory> <output .cov> [excluded directory]...`. */
final class ReportCommand
{
    public const string USAGE = 'usage: report.php <runs directory> <source directory> <output .cov file> [excluded directory]...';

    /**
     * @param list<string> $arguments the command-line arguments, without the script name
     *
     * @return string what to tell the user
     */
    public static function run(array $arguments): string
    {
        if (count($arguments) < 3) {
            throw new InvalidArgumentException(self::USAGE);
        }

        [$runDirectory, $sourceDirectory, $outputFile] = $arguments;
        $excluded = array_slice($arguments, 3);

        $startedAt = microtime(true);
        ReportWriter::write(Run::loadDirectory($runDirectory), [$sourceDirectory], $excluded, $outputFile);

        return sprintf('report built in %.2fs', microtime(true) - $startedAt);
    }
}
