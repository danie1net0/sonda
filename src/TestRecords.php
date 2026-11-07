<?php

declare(strict_types=1);

namespace Sonda;

/**
 * What each finished test hit, for the report to say which tests cover a line.
 *
 * The tests of a suite mostly hit the same probes of the same files, so each distinct pair of a
 * file id and its packed probe ids is kept once, in a table, and a test keeps only the packed ids
 * of its pairs. On a suite of 18 thousand tests, the 1.2 million pairs its tests hit are 24
 * thousand distinct ones, and the saved records take 15 MB instead of 75 MB.
 *
 * @phpstan-type Size 'small'|'medium'|'large'|'unknown'
 * @phpstan-type TestRecord array{0: string, 1: Size, 2: bool, 3: float, 4: string}
 * @phpstan-type Pair array{0: string, 1: string}
 */
final class TestRecords
{
    /** @var array<string, TestRecord> test id => [test file, size, passed, seconds, pair ids packed] */
    public private(set) array $records = [];

    /** @var array<int, Pair> pair id => [file id, probe ids packed] */
    public private(set) array $pairs = [];

    /** @var array<string, array<string, int>> file id => probe ids packed => pair id */
    private array $pairIdLookup = [];

    /** @return array{records: array<string, TestRecord>, pairs: array<int, Pair>} */
    public function __serialize(): array
    {
        $data = [];
        $data['records'] = $this->records;
        $data['pairs'] = $this->pairs;

        return $data;
    }

    /**
     * The lookup of the pair ids is left out of the saved records and built again here.
     *
     * @param array{records: array<string, TestRecord>, pairs: array<int, Pair>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->records = $data['records'];
        $this->pairs = $data['pairs'];

        foreach ($this->pairs as $pairId => [$fileId, $packed]) {
            $this->pairIdLookup[$fileId][$packed] = $pairId;
        }
    }

    /**
     * The records of several runs in one, starting from the first: a test that more than one run
     * recorded keeps the record of the first.
     *
     * @param non-empty-list<self> $all
     */
    public static function merge(array $all): self
    {
        $first = array_shift($all);
        $merged = clone $first;

        foreach ($all as $records) {
            $merged->absorb($records);
        }

        return $merged;
    }

    /**
     * @param Size                  $size
     * @param array<string, string> $packedHits file id => probe ids packed
     */
    public function add(string $testId, string $testFile, string $size, bool $passed, float $seconds, array $packedHits): void
    {
        $pairIds = [];

        foreach ($packedHits as $fileId => $packed) {
            $pairIds[] = $this->pairId((string) $fileId, $packed);
        }

        $this->records[$testId] = [$testFile, $size, $passed, $seconds, pack('V*', ...$pairIds)];
    }

    /**
     * The pairs a test hit, from the packed ids of its record.
     *
     * @return array<int, Pair> pair id => [file id, probe ids packed]
     */
    public function pairsOf(string $packedPairIds): array
    {
        $pairs = [];

        /** @var array<int, int> $pairIds a packed list of ids always unpacks */
        $pairIds = unpack('V*', $packedPairIds);

        foreach ($pairIds as $pairId) {
            $pairs[$pairId] = $this->pairs[$pairId];
        }

        return $pairs;
    }

    /** Adds the records of the tests this one lacks, their pairs moved into this table. */
    private function absorb(self $other): void
    {
        foreach ($other->records as $testId => [$testFile, $size, $passed, $seconds, $packedPairIds]) {
            if (isset($this->records[$testId])) {
                continue;
            }

            $this->add($testId, $testFile, $size, $passed, $seconds, array_column($other->pairsOf($packedPairIds), 1, 0));
        }
    }

    private function pairId(string $fileId, string $packed): int
    {
        if (isset($this->pairIdLookup[$fileId][$packed])) {
            return $this->pairIdLookup[$fileId][$packed];
        }

        $pairId = count($this->pairs);
        $this->pairs[] = [$fileId, $packed];
        $this->pairIdLookup[$fileId][$packed] = $pairId;

        return $pairId;
    }
}
