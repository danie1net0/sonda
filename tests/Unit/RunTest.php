<?php

declare(strict_types=1);

use Sonda\InstrumentationCache;
use Sonda\InstrumentationResult;
use Sonda\Probe;
use Sonda\Run;

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
        ->toThrow(RuntimeException::class, "O arquivo {$path} não contém uma execução do sonda.");
});

it('fails to load a missing file', function (): void {
    expect(fn (): Run => Run::load('/missing/run.ser'))
        ->toThrow(RuntimeException::class, 'O arquivo /missing/run.ser não contém uma execução do sonda.');
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

it('saves under a name of its own in a directory it creates', function (): void {
    $directory = TemporaryDirectory::create() . '/coverage/runs';
    $run       = new Run('/cache', ['abc' => ['/app/Order.php', 'key']], ['abc' => [0 => 1]]);

    $first  = $run->saveIn($directory);
    $second = $run->saveIn($directory);

    expect($first)->not->toBe($second)
        ->and(fileperms($directory) & 0777)->toBe(0777 & ~umask())
        ->and(dirname($first))->toBe($directory)
        ->and(basename($first))->toMatch('/^run-' . getmypid() . '-[0-9a-f]{8}\.ser$/')
        ->and(Run::load($first))->toEqual($run)
        ->and(Run::load($second))->toEqual($run);
});

it('merges the runs of every process of a suite', function (): void {
    $directory = TemporaryDirectory::create();
    (new Run('/cache', ['a' => ['/app/A.php', 'ka']], ['a' => [0 => 1]]))->saveIn($directory);
    (new Run('/cache', ['a' => ['/app/A.php', 'ka'], 'b' => ['/app/B.php', 'kb']], ['a' => [2 => 1], 'b' => [1 => 1]]))->saveIn($directory);
    (new Run('/cache', ['c' => ['/app/C.php', 'kc']], []))->saveIn($directory);
    file_put_contents($directory . '/notes.txt', 'not a run');

    $run   = Run::loadDirectory($directory);
    $hitsA = $run->hits['a'];
    ksort($hitsA);

    expect($run->cacheDirectory)->toBe('/cache')
        ->and($run->files)->toEqualCanonicalizing(['a' => ['/app/A.php', 'ka'], 'b' => ['/app/B.php', 'kb'], 'c' => ['/app/C.php', 'kc']])
        ->and($hitsA)->toBe([0 => 1, 2 => 1])
        ->and($run->hits['b'])->toBe([1 => 1])
        ->and($run->hits)->toHaveCount(2);
});

it('fails to load a directory without runs', function (): void {
    $directory = TemporaryDirectory::create();

    expect(fn (): Run => Run::loadDirectory($directory))
        ->toThrow(RuntimeException::class, "O diretório {$directory} não tem execuções do sonda.");
});

it('keeps the cache directory of the first run when merging', function (): void {
    $merged = Run::merge([new Run('/first', [], []), new Run('/second', [], [])]);

    expect($merged->cacheDirectory)->toBe('/first');
});
