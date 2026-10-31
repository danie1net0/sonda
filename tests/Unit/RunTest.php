<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};

it('saves and loads a run', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    $run = new Run('/cache', ['abc' => ['/app/Order.php', 'key']], ['abc' => [0 => 1]], ['T::t' => ['/tests/T.php', 'small', true, 0.5, ['abc' => pack('V', 0)]]]);

    $run->save($path);

    expect(Run::load($path))->toEqual($run);
});

it('has no tests nor problems unless given', function (): void {
    expect(new Run('/cache', [], [])->tests)->toBe([])
        ->and(new Run('/cache', [], [])->problems)->toBe([]);
});

it('merges the problems of every process of a suite, each once', function (): void {
    $merged = Run::merge([new Run('/cache', [], [], [], ['a', 'b']), new Run('/cache', [], [], [], ['b', 'c'])]);

    expect($merged->problems)->toBe(['a', 'b', 'c']);
});

it('merges the tests of every process of a suite', function (): void {
    $first = ['/tests/A.php', 'unknown', true, 0.1, []];
    $second = ['/tests/B.php', 'unknown', false, 0.2, []];

    $merged = Run::merge([new Run('/cache', [], [], ['A::a' => $first]), new Run('/cache', [], [], ['B::b' => $second])]);

    expect($merged->tests)->toBe(['A::a' => $first, 'B::b' => $second]);
});

it('fails to load a file that is not a run', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    file_put_contents($path, serialize(['not', 'a', 'run']));

    expect(fn (): Run => Run::load($path))
        ->toThrow(RuntimeException::class, "The file {$path} does not hold a sonda run.");
});

it('fails to load a missing file', function (): void {
    expect(fn (): Run => Run::load('/missing/run.ser'))
        ->toThrow(RuntimeException::class, 'The file /missing/run.ser could not be read.');
});

it('reads the probes of a file from its cache', function (): void {
    $directory = TemporaryDirectory::create();
    $probes = [0 => new Probe(1, true, 'f', 1, 1)];
    new InstrumentationCache($directory)->store('key', new InstrumentationResult('<?php', $probes));

    expect(new Run($directory, [], [])->probesOf('key'))->toEqual($probes);
});

it('does not revive objects other than runs', function (): void {
    $path = TemporaryDirectory::create() . '/run.ser';
    file_put_contents($path, serialize(new Run('/cache', [], ['abc' => [new ArrayObject()]])));

    expect(Run::load($path)->hits['abc'][0])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('saves under a name of its own in a directory it creates', function (): void {
    $directory = TemporaryDirectory::create() . '/coverage/runs';
    $run = new Run('/cache', ['abc' => ['/app/Order.php', 'key']], ['abc' => [0 => 1]]);

    $first = $run->saveIn($directory);
    $second = $run->saveIn($directory);

    expect($first)->not->toBe($second)
        ->and(fileperms($directory) & 0o777)->toBe(0o700)
        ->and(dirname($first))->toBe($directory)
        ->and(basename($first))->toMatch('/^run-' . getmypid() . '-[0-9a-f]{8}\.ser$/')
        ->and(Run::load($first))->toEqual($run)
        ->and(Run::load($second))->toEqual($run);
});

it('merges the runs of every process of a suite', function (): void {
    $directory = TemporaryDirectory::create();
    new Run('/cache', ['a' => ['/app/A.php', 'ka']], ['a' => [0 => 1]])->saveIn($directory);
    new Run('/cache', ['a' => ['/app/A.php', 'ka'], 'b' => ['/app/B.php', 'kb']], ['a' => [2 => 1], 'b' => [1 => 1]])->saveIn($directory);
    new Run('/cache', ['c' => ['/app/C.php', 'kc']], [])->saveIn($directory);
    file_put_contents($directory . '/notes.txt', 'not a run');

    $run = Run::loadDirectory($directory);
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
        ->toThrow(RuntimeException::class, "The directory {$directory} has no sonda runs.");
});

it('keeps the cache directory of the first run when merging', function (): void {
    $merged = Run::merge([new Run('/first', [], []), new Run('/second', [], [])]);

    expect($merged->cacheDirectory)->toBe('/first');
});

it('reads the line map and the analysis copy of a view from its cache', function (): void {
    $directory = TemporaryDirectory::create();
    new InstrumentationCache($directory)->store('view', new InstrumentationResult('<?php', [], [1 => 4], '<?php clean();'));
    $run = new Run($directory, [], []);

    expect($run->lineMapOf('view'))->toBe([1 => 4])
        ->and($run->lineMapOf('missing'))->toBeNull()
        ->and($run->analysisPathOf('view'))->toBe($directory . '/view.view.php');
});
