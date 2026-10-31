<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\CoverageBuilder;
use SebastianBergmann\CodeCoverage\Node\File;

/**
 * The report node of a file with a function, a class method and a trait method, of which the
 * branch on line 7 ran and the one on line 13 did not.
 */
function compatFileNode(): File
{
    $root = TemporaryDirectory::create();
    $path = $root . '/Units.php';
    file_put_contents($path, "<?php\n\nfunction helper(): int\n{\n    return 1;\n}\n\nfinal class Order\n{\n    public function total(int \$a): int\n    {\n        return \$a;\n    }\n}\n\ntrait Named\n{\n    public function name(): string\n    {\n        return 'n';\n    }\n}\n");
    new InstrumentationCache($root . '/cache')->store('key', new InstrumentationResult('<?php', [
        0 => new Probe(5, true, 'helper', 5, 5),
        1 => new Probe(12, true, 'Order->total', 12, 12),
    ]));
    $coverage = CoverageBuilder::build(new Run($root . '/cache', ['u' => [$path, 'key']], ['u' => [0 => 1]]), [$path]);

    foreach ($coverage->getReport() as $node) {
        if ($node instanceof File) {
            return $node;
        }
    }

    throw new LogicException('The report has no file.');
}

/** A branch as the compatibility layer hands it out. @return array{op_start: int, op_end: int, line_start: int, line_end: int, hit: list<string>, out: array<int, int>, out_hit: array<int, int>} */
function compatBranch(int $id, int $line, array $hit): array
{
    return ['op_start' => $id, 'op_end' => $id, 'line_start' => $line, 'line_end' => $line, 'hit' => $hit, 'out' => [], 'out_hit' => []];
}
