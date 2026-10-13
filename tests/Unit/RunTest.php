<?php

declare(strict_types=1);

use CovProto\InstrumentationCache;
use CovProto\InstrumentationResult;
use CovProto\Probe;
use CovProto\Run;

it('saves and loads a run', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    $run  = new Run('/cache', ['abc' => ['/app/Order.php', 'key']], ['abc' => [0 => 1]]);

    $run->save($path);

    expect(Run::load($path))->toEqual($run);
});

it('fails to load a file that is not a run', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    file_put_contents($path, serialize(['not', 'a', 'run']));

    expect(fn (): Run => Run::load($path))
        ->toThrow(RuntimeException::class, "O arquivo {$path} não contém uma execução do covproto.");
});

it('fails to load a missing file', function (): void {
    expect(fn (): Run => Run::load('/missing/run.ser'))
        ->toThrow(RuntimeException::class, 'O arquivo /missing/run.ser não contém uma execução do covproto.');
});

it('reads the probes of a file from its cache', function (): void {
    $directory = TemporaryDirectory::create();
    $probes    = [0 => new Probe(1, true, 'f', 1, 1)];
    (new InstrumentationCache($directory))->store('key', new InstrumentationResult('<?php', $probes));

    expect((new Run($directory, [], []))->probesOf('key'))->toEqual($probes);
});

it('does not revive objects other than runs', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    file_put_contents($path, serialize(new Run('/cache', [], ['abc' => [new ArrayObject]])));

    expect(Run::load($path)->hits['abc'][0])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});
