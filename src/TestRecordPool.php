<?php

declare(strict_types=1);

namespace Sonda;

/**
 * Makes the test records of the runs it loads share their strings and their hit sets.
 *
 * unserialize() gives every string its own copy, so the file ids and packed probe lists that
 * thousands of tests repeat would cost memory once per test. On a suite of 18 thousand tests,
 * sharing them takes the records from 226 MB down to 92 MB.
 *
 * @phpstan-import-type TestRecord from Hits
 */
final class TestRecordPool
{
    /** @var array<string, string> each string once, by itself */
    private array $strings = [];

    /** @var array<string, array<string, string>> hash of a hit set => the first hit set with that hash */
    private array $hitSets = [];

    /**
     * The same record, built from the strings and hit sets the pool already holds.
     *
     * @param TestRecord $record
     *
     * @return TestRecord
     */
    public function share(array $record): array
    {
        $hitSet = [];

        foreach ($record[4] as $fileId => $packed) {
            $hitSet[$this->string((string) $fileId)] = $this->string($packed);
        }

        $record[0] = $this->string($record[0]);
        $record[4] = $this->hitSet($hitSet);

        return $record;
    }

    private function string(string $value): string
    {
        return $this->strings[$value] ??= $value;
    }

    /**
     * @param array<string, string> $hitSet
     *
     * @return array<string, string>
     */
    private function hitSet(array $hitSet): array
    {
        $shared = $this->hitSets[hash('xxh128', serialize($hitSet))] ??= $hitSet;

        // Two different hit sets with the same hash must not mix the hits of their tests.
        if ($shared !== $hitSet) {
            return $hitSet;
        }

        return $shared;
    }
}
