<?php

declare(strict_types=1);

use Sonda\{InstrumentationResult, Probe};

it('holds the instrumented code and its probes', function (): void {
    $probe = new Probe(1, true, 'f', 1, 1);
    $result = new InstrumentationResult('<?php', [0 => $probe]);

    expect($result)
        ->code->toBe('<?php')
        ->probes->toBe([0 => $probe]);
});

it('carries the line map and the analysis copy of a view, and none for a plain file', function (): void {
    $view = new InstrumentationResult('<?php', [], [1 => 3], '<?php clean();');
    $plain = new InstrumentationResult('<?php', []);

    expect($view)
        ->lineMap->toBe([1 => 3])
        ->analysisCode->toBe('<?php clean();')
        ->and($plain)
        ->lineMap->toBeNull()
        ->analysisCode->toBeNull();
});
