<?php

declare(strict_types=1);

use CovProto\InstrumentationResult;
use CovProto\Probe;

it('holds the instrumented code and its probes', function (): void {
    $probe  = new Probe(1, true, 'f', 1, 1);
    $result = new InstrumentationResult('<?php', [0 => $probe]);

    expect($result)
        ->code->toBe('<?php')
        ->probes->toBe([0 => $probe]);
});
