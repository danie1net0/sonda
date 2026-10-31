<?php

declare(strict_types=1);

namespace Sonda\Project;

use function str_ends_with, str_starts_with;

/**
 * The test files a change affects, by the test map: those whose tests ran a changed source file,
 * plus the test files that changed. Whatever the map cannot answer for runs the whole suite: a
 * source file no test is known to run (new, or never run), a deleted source file, a test support
 * file, and any other file (configuration, routes, migrations, dependencies), since any test may
 * read it.
 */
final readonly class AffectedTests
{
    /**
     * @param list<string>|null $tests  the test files to run, or null for the whole suite
     * @param string            $reason why the whole suite runs, empty otherwise
     */
    public function __construct(
        public ?array $tests,
        public string $reason = '',
    ) {
    }

    /**
     * @param list<string> $changed     every changed path, deleted ones included
     * @param list<string> $sourceFiles every source file of the project
     * @param list<string> $testPaths   the directories and files of the test suites
     */
    public static function of(array $changed, TestMap $map, array $sourceFiles, array $testPaths): self
    {
        if ($map->sources === []) {
            return new self(null, 'this project has no test map yet; this run creates it');
        }

        $isSource = array_flip($sourceFiles);
        $changedSources = [];
        $tests = [];

        foreach ($changed as $path) {
            if (isset($isSource[$path])) {
                if (! $map->knows($path)) {
                    return new self(null, "no known test runs {$path}");
                }

                $changedSources[] = $path;
            } elseif (isset($map->sources[$path]) || (self::isUnder($path, $testPaths) && str_ends_with($path, 'Test.php'))) {
                // A test file deleted since has nothing left to run.
                if (is_file($path)) {
                    $tests[$path] = $path;
                }
            } else {
                return new self(null, "{$path} changed and is neither a <source> file nor a test");
            }
        }

        foreach ($map->testsOf($changedSources) as $test) {
            $tests[$test] = $test;
        }

        ksort($tests);

        return new self(array_values($tests));
    }

    /** @param list<string> $paths */
    private static function isUnder(string $path, array $paths): bool
    {
        return array_any($paths, static fn (string $testPath): bool => $path === $testPath || str_starts_with($path, $testPath . '/'));
    }
}
