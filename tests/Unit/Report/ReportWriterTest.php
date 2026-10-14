<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\ReportWriter;
use SebastianBergmann\CodeCoverage\CodeCoverage;

it('writes a php-code-coverage file with the lines and branches of the run', function (): void {
    $directory = TemporaryDirectory::create();
    mkdir($directory . '/app/Providers', 0777, true);
    $order = $directory . '/app/Order.php';
    file_put_contents($order, <<<'PHP'
    <?php

    final class Order
    {
        public function total(int $quantity): int
        {
            if ($quantity > 10) {
                return 10;
            }

            return $quantity;
        }
    }
    PHP);
    file_put_contents($directory . '/app/Providers/AppProvider.php', "<?php\n\nfinal class AppProvider\n{\n}\n");
    $unused = $directory . '/app/Unused.php';
    file_put_contents($unused, "<?php\n\nfunction unused(): int\n{\n    return 1;\n}\n");

    new InstrumentationCache($directory . '/cache')->store('key', new InstrumentationResult('<?php', [
        0 => new Probe(7, true, 'Order->total', 7, 11),
        1 => new Probe(8, true, 'Order->total', 8, 8),
        2 => new Probe(11, false, 'Order->total', 0, 0),
    ]));
    $run = new Run($directory . '/cache', ['abc' => [$order, 'key']], ['abc' => [0 => 1, 2 => 1]]);
    $output = $directory . '/coverage.cov';

    ReportWriter::write($run, $directory . '/app', [$directory . '/app/Providers'], $output);

    $coverage = require $output;
    $data = $coverage->getData();
    $lines = array_map(static fn (?array $tests): ?bool => $tests === null ? null : $tests !== [], $data->lineCoverage()[$order]);
    $branches = $data->functionCoverage()[$order]['Order->total']->branches;

    expect($coverage)->toBeInstanceOf(CodeCoverage::class)
        ->and(array_keys($data->lineCoverage()))->toEqualCanonicalizing([$order, $unused])
        ->and($data->lineCoverage()[$unused])->toBe([5 => []])
        ->and($lines)->toBe([7 => true, 8 => false, 11 => true])
        ->and(array_map(static fn (object $branch): bool => $branch->hit !== [], $branches))->toBe([true, false]);
});

it('refuses an empty output path', function (): void {
    expect(fn () => ReportWriter::write(new Run('/cache', [], []), '/app', [], ''))
        ->toThrow(InvalidArgumentException::class, 'O caminho do relatório não pode ser vazio.');
});
