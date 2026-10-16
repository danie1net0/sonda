<?php

declare(strict_types=1);

namespace Sonda;

use RuntimeException;

/**
 * Instrumented copies of the source files and their probe maps, keyed by a hash of the
 * source path, its modification time and the instrumenter fingerprint.
 */
final readonly class InstrumentationCache
{
    public function __construct(private string $directory)
    {
    }

    public function codePath(string $key): string
    {
        return $this->directory . '/' . $key . '.php';
    }

    public function has(string $key): bool
    {
        return is_file($this->codePath($key));
    }

    /** The path of the plain compiled code of a view, the file the report analyses. */
    public function analysisPath(string $key): string
    {
        return $this->directory . '/' . $key . '.view.php';
    }

    /** The map is written before the code, so a cached file always has its map. */
    public function store(string $key, InstrumentationResult $result): void
    {
        Filesystem::privateDirectory($this->directory);

        $this->writeAtomically($this->mapPath($key), serialize($result->probes));

        if ($result->lineMap !== null) {
            $this->writeAtomically($this->linesPath($key), serialize($result->lineMap));
            $this->writeAtomically($this->analysisPath($key), $result->analysisCode ?? '');
        }

        $this->writeAtomically($this->codePath($key), $result->code);
    }

    /**
     * The line map of a view, or null for a file instrumented as it is.
     *
     * @return array<int, int>|null compiled line => template line
     */
    public function lineMap(string $key): ?array
    {
        if (! is_file($this->linesPath($key))) {
            return null;
        }

        $contents = unserialize(Filesystem::read($this->linesPath($key)), ['allowed_classes' => false]);
        $map = [];

        foreach (is_array($contents) ? $contents : [null] as $compiledLine => $templateLine) {
            if (! is_int($compiledLine) || ! is_int($templateLine)) {
                throw new RuntimeException("O mapa de linhas {$key} está corrompido no cache {$this->directory}.");
            }

            $map[$compiledLine] = $templateLine;
        }

        return $map;
    }

    /** @return array<int, Probe> */
    public function probes(string $key): array
    {
        $contents = unserialize(Filesystem::read($this->mapPath($key)), ['allowed_classes' => [Probe::class]]);
        $probes = [];

        foreach (is_array($contents) ? $contents : [null] as $id => $probe) {
            if (! is_int($id) || ! $probe instanceof Probe) {
                throw new RuntimeException("O mapa de sondas {$key} está corrompido no cache {$this->directory}.");
            }

            $probes[$id] = $probe;
        }

        return $probes;
    }

    private function mapPath(string $key): string
    {
        return $this->directory . '/' . $key . '.map';
    }

    private function linesPath(string $key): string
    {
        return $this->directory . '/' . $key . '.lines';
    }

    /** Parallel test workers may write the same file: each writes its own temporary file. */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporary = tempnam($this->directory, 'sonda');

        file_put_contents($temporary, $contents);
        rename($temporary, $path);
    }
}
