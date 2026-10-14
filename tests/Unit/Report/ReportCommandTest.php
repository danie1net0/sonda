<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Report\ReportCommand;

it('requires the run directory, the source directory and the output file', function (): void {
    expect(fn (): string => ReportCommand::run(['/runs', 'app']))
        ->toThrow(InvalidArgumentException::class, ReportCommand::USAGE);
});

it('builds the report of a run directory, leaving out the excluded directories', function (): void {
    $directory = TemporaryDirectory::create();
    mkdir($directory . '/app/Providers', 0777, true);
    $order = $directory . '/app/Order.php';
    file_put_contents($order, "<?php\n\nfinal class Order\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");
    file_put_contents($directory . '/app/Providers/AppProvider.php', "<?php\n\nfinal class AppProvider\n{\n}\n");
    new InstrumentationCache($directory . '/cache')->store('key', new InstrumentationResult('<?php', [0 => new Probe(7, true, 'Order->total', 7, 7)]));
    new Run($directory . '/cache', ['abc' => [$order, 'key']], ['abc' => [0 => 1]])->saveIn($directory . '/runs');

    $message = ReportCommand::run([$directory . '/runs', $directory . '/app', $directory . '/coverage.cov', $directory . '/app/Providers']);

    $coverage = require $directory . '/coverage.cov';
    preg_match('/^report built in (\d+\.\d\d)s$/', $message, $duration);

    expect($duration)->toHaveCount(2)
        ->and((float) $duration[1])->toBeLessThan(60.0)
        ->and(array_keys($coverage->getData()->lineCoverage()))->toBe([$order]);
});

it('builds the report with the three required arguments only', function (): void {
    $directory = TemporaryDirectory::create();
    mkdir($directory . '/app/Providers', 0777, true);
    $order = $directory . '/app/Order.php';
    $provider = $directory . '/app/Providers/AppProvider.php';
    file_put_contents($order, "<?php\n\nfinal class Order\n{\n}\n");
    file_put_contents($provider, "<?php\n\nfinal class AppProvider\n{\n}\n");
    new Run($directory . '/cache', [], [])->saveIn($directory . '/runs');

    ReportCommand::run([$directory . '/runs', $directory . '/app', $directory . '/coverage.cov']);

    $coverage = require $directory . '/coverage.cov';

    expect(array_keys($coverage->getData()->lineCoverage()))->toEqualCanonicalizing([$order, $provider]);
});
