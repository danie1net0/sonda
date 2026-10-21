<?php

declare(strict_types=1);

namespace Sonda\Project;

use Closure;
use TypeError;
use Sonda\{Filesystem, Run};

use function dirname, in_array, str_starts_with, substr;

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

    /**
     * The map saved at a path, or an empty one when there is none yet or it cannot be read. The
     * file keeps the paths relative to the project, so that a map cached by CI serves a checkout
     * of the project in another directory.
     */
    public static function load(string $path, string $projectRoot): self
    {
        if (! is_file($path)) {
            return new self();
        }

        $map = unserialize(Filesystem::read($path), ['allowed_classes' => [self::class]]);

        if (! $map instanceof self) {
            return new self();
        }

        $root = rtrim($projectRoot, '/') . '/';

        try {
            return $map->mapped(static fn (string $path): string => str_starts_with($path, '/') ? $path : $root . $path);
        } catch (TypeError) {
            // A path that is no string: not a map this command wrote.
            return new self();
        }
    }

    /** Written whole and then moved into place: a parallel run never reads half a map. */
    public function save(string $path, string $projectRoot): void
    {
        Filesystem::ensureDirectory(dirname($path));
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        $root = rtrim($projectRoot, '/') . '/';
        file_put_contents($temporary, serialize($this->mapped(static fn (string $path): string => str_starts_with($path, $root) ? substr($path, strlen($root)) : $path)));
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

    /** @param Closure(string): string $move what becomes of each path */
    private function mapped(Closure $move): self
    {
        $sources = [];

        foreach ($this->sources as $testFile => $ran) {
            $sources[$move($testFile)] = array_map($move, $ran);
        }

        return new self($sources);
    }
}
