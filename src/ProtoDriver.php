<?php declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

final class ProtoDriver extends Driver
{
    public function name(): string { return 'covproto'; }
    public function version(): string { return '0.1'; }
    public function nameAndVersion(): string { return 'covproto 0.1'; }
    public function start(): void {}
    public function stop(): RawCodeCoverageData { return RawCodeCoverageData::fromLineCoverage([]); }
    protected function canCollectBranchCoverage(): bool { return true; }
    protected function canCollectPathCoverage(): bool { return true; }
    public function canCollectBranchAndPathCoverage(): bool { return true; }
}
