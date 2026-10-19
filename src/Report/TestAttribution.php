<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\{Probe, Run};

/**
 * Which tests executed each line and each branch of a run, in the keys of its coverage data:
 * what the report shows when a covered line is hovered.
 *
 * A test's hits are expanded once per distinct set of probes of a file: the tests of a suite
 * mostly hit the same probes of the code every test runs.
 */
final readonly class TestAttribution
{
    /**
     * @param array<string, array<int, list<string>>>                         $lines    key => line => test ids
     * @param array<string, array<int, list<string>>>                         $branches key => probe id of the branch => test ids
     * @param array<string, array{size: string, status: string, time: float}> $tests    the tests, as php-code-coverage describes them
     */
    public function __construct(
        public array $lines,
        public array $branches,
        public array $tests,
    ) {
    }

    /** @param list<string> $sourceFiles only these files enter the report */
    public static function of(Run $run, array $sourceFiles): self
    {
        $keys = RawCoverageBuilder::keysOf($run, $sourceFiles);
        $expanded = [];
        $probes = [];
        $lines = [];
        $branches = [];
        $tests = [];

        foreach ($run->tests as $testId => [, $size, $passed, $time, $packedHits]) {
            $test = [];
            $test['size'] = $size;
            $test['status'] = $passed ? 'success' : 'failure';
            $test['time'] = $time;
            $tests[$testId] = $test;

            foreach ($packedHits as $fileId => $packed) {
                if (! isset($keys[$fileId])) {
                    continue;
                }

                $key = $keys[$fileId];
                $probes[$fileId] ??= $run->probesOf($run->files[$fileId][1]);
                [$hitLines, $hitBranches] = $expanded[$fileId][$packed] ??= self::expand($probes[$fileId], $packed);

                foreach ($hitLines as $line) {
                    $lines[$key][$line][] = $testId;
                }

                foreach ($hitBranches as $probeId) {
                    $branches[$key][$probeId][] = $testId;
                }
            }
        }

        return new self($lines, $branches, $tests);
    }

    /**
     * The lines and the branch probes a set of probes of one file stands for.
     *
     * @param array<int, Probe> $probes every probe of the file
     *
     * @return array{0: array<int, int>, 1: list<int>} the lines, keyed by themselves, and the branch probes
     */
    private static function expand(array $probes, string $packed): array
    {
        $lines = [];
        $branches = [];

        /** @var array<int, int> $probeIds a packed list of ids always unpacks */
        $probeIds = unpack('V*', $packed);

        foreach ($probeIds as $probeId) {
            $probe = $probes[$probeId];

            foreach ($probe->lines() as $line) {
                $lines[$line] = $line;
            }

            if ($probe->countsAsBranch()) {
                $branches[] = $probeId;
            }
        }

        $expansion = [];
        $expansion[] = $lines;
        $expansion[] = $branches;

        return $expansion;
    }
}
