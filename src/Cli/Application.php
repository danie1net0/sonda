<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;
use RuntimeException;

use function in_array;

final readonly class Application
{
    public const string USAGE = <<<'TEXT'
    usage: sonda test [options] [runner arguments]

    Runs the tests of the project in the current directory with Sonda's instrumentation and
    prints the line and branch coverage of the <source> of phpunit.xml. Nothing is installed
    in the project.

    Options:
      --changed=<ref>   measures only the <source> files changed since the merge-base with the
                        reference (committed, modified and new); fails if anything is left uncovered
      --files=a,b       measures only these files; fails if anything is left uncovered
      --html=<dir>      writes the HTML report
      --clover=<file>   writes the Clover report
      --cov=<file>      writes the .cov of php-code-coverage
      --cobertura=<file> writes the Cobertura report (what GitLab reads in the MR)
      --code-quality=<file> writes what is left uncovered as GitLab Code Quality (shown in the diff)
      --no-strict       does not require the else of every if nor the null side of ?->
      --no-fast         probes without the local reference per function
      --no-parallel     does not use ParaTest even when installed
      --no-blade        does not measure the Blade views (resources/views) of a Laravel project
      --views=a,b       measures the Blade views of these directories too
      --text            lists the coverage of each file
      --affected        with --changed or --files, runs only the tests that execute what changed,
                        by the map each run writes; without a map, or with a change it does not
                        explain (config, routes, a new file), runs the whole suite
      --includes        measures the files loaded by a direct include/require too (default: only
                        when the source has files without a class, such as migrations)
      --no-includes     does not measure those files
      --map=<file>      keeps the test map of --affected in this file (for the CI cache)
      --covered-by      says in the reports which tests executed each line and branch (heavy:
                        in a large project the HTML passes 1 GB)
      --min-lines=<n>   fails if the line coverage of the project is below n%
      --min-branches=<n> the same for branches
      -c <file>         the phpunit.xml to use, also passed on to the runner

    Any other argument goes to the runner (php artisan test, pest or phpunit).

    sonda --version prints the version of this sonda.

    usage: sonda compare <output> [options] [runner arguments]

    Measures the project with Sonda and with Xdebug (path coverage) and compares the two,
    writing sonda.cov, xdebug.cov, compare.txt and summary.txt in <output>. Needs a private
    database in DB_DATABASE in the environment and an idle machine.

    Options:
      --processes=<n>   ParaTest workers, or batch queues (default 3)
      --batch=<n>       tests per batch on the Xdebug side, which in a large suite does not finish in one go
      --xdebug=<so>     the xdebug.so to load
    TEXT;

    public function __construct(private TestCommand $testCommand, private CompareCommand $compareCommand, private string $version)
    {
    }

    /**
     * @param list<string> $arguments
     * @param resource     $output
     * @param resource     $error
     */
    public function run(array $arguments, string $projectRoot, $output, $error): int
    {
        $command = $arguments[0] ?? 'help';

        if (in_array($command, ['help', '--help', '-h'], true)) {
            fwrite($output, self::USAGE . "\n");

            return 0;
        }

        if (in_array($command, ['--version', '-V'], true)) {
            fwrite($output, "sonda {$this->version}\n");

            return 0;
        }

        if (! in_array($command, ['test', 'compare'], true)) {
            fwrite($error, "Unknown command: {$command}\n\n" . self::USAGE . "\n");

            return 1;
        }

        try {
            if ($command === 'compare') {
                return $this->compareCommand->run(CompareOptions::parse(array_slice($arguments, 1)), $projectRoot);
            }

            return $this->testCommand->run(TestOptions::parse(array_slice($arguments, 1)), $projectRoot, $output);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($error, $exception->getMessage() . "\n");

            return 1;
        }
    }
}
