<?php

declare(strict_types=1);

namespace Sonda\Project;

use function in_array, str_starts_with;

/** The test paths among the arguments handed to the runner, apart from its options and its configuration file. */
final readonly class TestSelection
{
    /**
     * Options whose next token is a value in PHPUnit, Pest or ParaTest. Optional values such
     * as PHPUnit's --colors must use '='; a bare flag must not swallow the following path.
     * Inline values and unknown options are left to the runner to interpret and validate.
     *
     * @var list<string>
     */
    private const array VALUE_OPTIONS = [
        '--atleast-version', '--bootstrap', '--cache-directory', '--columns',
        '--coverage-filter', '--coverage-clover', '--coverage-cobertura', '--coverage-crap4j',
        '--coverage-html', '--coverage-openclover', '--coverage-php', '--coverage-xml', '--coverage-jsonl',
        '--default-time-limit', '--exclude-group', '--filter', '--exclude-filter',
        '--generate-baseline', '--use-baseline', '--group', '--covers', '--uses',
        '--requires-php-extension', '--include-path', '--list-tests-xml', '--log-junit',
        '--log-otr', '--log-teamcity', '--order-by', '--random-order-seed', '--testdox-html',
        '--testdox-text', '--test-suffix', '--testsuite', '--exclude-testsuite',
        '--log-events-text', '--log-events-verbose-text', '--extension', '-d',
        '--diff-context', '--repeat', '--retry', '--run-test-id', '--test-files-file',
        '--test-id-filter-file', '--timeout',
        '--max-batch-size', '-m', '--passthru-php', '--processes', '-p', '--max-processes',
        '--runner', '--tmp-dir', '--shard', '--shard-test-distribution',
        '--min', '--exactly', '--id', '--class', '--ignore', '--issue', '--pr', '--pull-request',
    ];

    /**
     * @param list<string> $options the arguments that are not paths, minus `-c`/`--configuration` and its file
     * @param list<string> $paths   test files and directories, as given
     */
    public function __construct(public array $options, public array $paths)
    {
    }

    /** @param list<string> $runnerArguments */
    public static function of(array $runnerArguments, bool $pest = false): self
    {
        $options = [];
        $paths = [];
        $skipNext = false;
        $keepNext = false;
        $pathsOnly = false;

        foreach ($runnerArguments as $argument) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($keepNext) {
                $options[] = $argument;
                $keepNext = false;

                continue;
            }

            if ($pathsOnly) {
                $paths[] = $argument;

                continue;
            }

            if ($argument === '--') {
                $pathsOnly = true;

                continue;
            }

            if ($argument === '-c' || $argument === '--configuration') {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($argument, '--configuration=')) {
                continue;
            }

            if (str_starts_with($argument, '-')) {
                $options[] = $argument;
                // Pest uses -p and --retry as flags, unlike ParaTest and PHPUnit 13.
                $keepNext = in_array($argument, self::VALUE_OPTIONS, true)
                    && (! $pest || ! in_array($argument, ['-p', '--retry'], true));

                continue;
            }

            $paths[] = $argument;
        }

        return new self($options, $paths);
    }
}
