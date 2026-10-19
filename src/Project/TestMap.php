<?php

declare(strict_types=1);

namespace Sonda\Project;

use Sonda\{Filesystem, Run};

use function in_array;

/**
 * Which source files the tests of each test file ran, kept between runs: what tells the tests
 * a change affects. Each run updates the test files it ran, for the source files it measured.
 */
final readonly class TestMap
{
    /** @param array<string, list<string>> $sources test file => source files its tests ran, sorted */
    public function __construct(public array $sources = [])
    {
    }

    /** The map saved at a path, or an empty one when there is none yet or it cannot be read. */
    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self();
        }

        $map = unserialize(Filesystem::read($path), ['allowed_classes' => [self::class]]);

        return $map instanceof self ? $map : new self();
    }

    /** Written whole and then moved into place: a parallel run never reads half a map. */
    public function save(string $path): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        file_put_contents($temporary, serialize($this));
        rename($temporary, $path);
    }

    /**
     * The map after a run: each test file it ran now maps to what its tests ran among the measured
     * source files, and keeps the rest it mapped before; a test file gone from disk leaves the map.
     *
     * @param list<string> $measured the source files the run measured
     */
    public function updatedWith(Run $run, array $measured): self
    {
        $ran = [];

        foreach ($run->tests as [$testFile, , , , $packedHits]) {
            $ran[$testFile] ??= [];

            foreach (array_keys($packedHits) as $fileId) {
                $ran[$testFile][] = $run->files[$fileId][0];
            }
        }

        $isMeasured = array_flip($measured);
        $sources = $this->sources;

        foreach ($ran as $testFile => $hit) {
            $kept = array_filter($sources[$testFile] ?? [], static fn (string $source): bool => ! isset($isMeasured[$source]));
            $merged = array_unique([...$kept, ...$hit]);
            sort($merged);
            $sources[$testFile] = $merged;
        }

        $sources = array_filter($sources, is_file(...), ARRAY_FILTER_USE_KEY);
        ksort($sources);

        return new self($sources);
    }

    /** Whether some test is known to run the source file. */
    public function knows(string $source): bool
    {
        return array_any($this->sources, static fn (array $sources): bool => in_array($source, $sources, true));
    }

    /**
     * The test files whose tests ran any of the source files.
     *
     * @param list<string> $sources
     *
     * @return list<string>
     */
    public function testsOf(array $sources): array
    {
        $wanted = array_flip($sources);

        return array_keys(array_filter($this->sources, static fn (array $ran): bool => array_any($ran, static fn (string $source): bool => isset($wanted[$source]))));
    }
}
