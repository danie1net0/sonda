<?php

declare(strict_types=1);

namespace Sonda\Cli;

/**
 * `sonda compare`: measures the project with the Sonda and with Xdebug's path coverage and
 * compares the two, through the script in tools/. The script reads the private database from
 * the environment (DB_DATABASE) and writes sonda.cov, xdebug.cov, compare.txt and summary.txt.
 */
final readonly class CompareCommand
{
    public function __construct(private ProcessRunner $processes, private string $sondaRoot)
    {
    }

    public function run(CompareOptions $options, string $projectRoot): int
    {
        $environment = [];

        if ($options->processes !== null) {
            $environment['PROCESSES'] = (string) $options->processes;
        }

        if ($options->batch !== null) {
            $environment['BATCH'] = (string) $options->batch;
        }

        if ($options->xdebug !== null) {
            $environment['XDEBUG_SO'] = $options->xdebug;
        }

        return $this->processes->run(
            ['bash', $this->sondaRoot . '/tools/compare-with-xdebug.sh', $options->outputDirectory, ...$options->runnerArguments],
            $environment,
            $projectRoot,
        );
    }
}
