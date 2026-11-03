<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use Closure;
use ReflectionProperty;
use Sonda\Report\CoverageDriver;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use SebastianBergmann\CodeCoverage\StaticAnalysis\Registry;

use function max;

/**
 * php-code-coverage 14 (PHPUnit 13), which keeps the tests of a line or a branch as a map of test
 * index to hits, shares its analysers in a registry and writes `.cov` files with a serializer.
 */
final class V14CoverageApi implements CoverageApi
{
    use ObjectCoverageData;

    /**
     * The key of the analyser in the registry for the settings of a report sonda builds: no
     * cache directory, annotations for ignoring code, deprecated code not ignored.
     */
    private const string ANALYSER_KEY = "\0" . '1' . "\0" . '0';

    public function enableBranches(CodeCoverage $coverage, CoverageDriver $driver): void
    {
        $driver->setGranularity(Granularity::LineBranchAndPath);
    }

    public function useAnalyser(CodeCoverage $coverage, object $analyser): void
    {
        // A report asks the registry for its analyser each time it analyses: the one sonda builds
        // takes the place of the registry's own until another report puts its own there.
        $analysers = new ReflectionProperty(Registry::class, 'analysers');
        /** @var array<string, object> $registered */
        $registered = $analysers->getValue();
        $registered[self::ANALYSER_KEY] = $analyser;
        $analysers->setValue(null, $registered);
    }

    public function testHits(ProcessedCodeCoverageData $data, array $tests): Closure
    {
        $indexes = array_flip($data->testIds());
        $next = max([-1, ...$indexes]) + 1;

        foreach ($tests as $test) {
            $indexes[$test] ??= $next++;
        }

        /** @var array<int<0, max>, non-empty-string> $ids php-code-coverage and the run name every test */
        $ids = array_flip($indexes);
        $data->setTestIds($ids);

        return static function (array $tests) use ($indexes): array {
            /** @var list<string> $tests */
            $hits = [];

            foreach ($tests as $test) {
                $hits[$indexes[$test]] = 1;
            }

            return $hits;
        };
    }

    public function mergedHits(array $first, array $second): array
    {
        // sonda counts no hits: a test that ran a line counts once, as php-code-coverage marks it.
        return $first + $second;
    }

    public function reportInput(CodeCoverage $coverage): Directory
    {
        return $coverage->getReport();
    }

    public function writeCov(CodeCoverage $coverage, string $file): void
    {
        /** @var non-empty-string $file the report writers refuse an empty path */
        new Serializer()->serialize($file, $coverage);
    }
}
