<?php

declare(strict_types=1);

use Sonda\InstrumentationCache;
use Sonda\InstrumentationResult;
use Sonda\Probe;

it('places the code of a key in its directory', function (): void {
    expect((new InstrumentationCache('/cache'))->codePath('abc'))->toBe('/cache/abc.php');
});

it('stores the code and the probe map, creating the directory', function (): void {
    $directory = TemporaryDirectory::create() . '/nested/cache';
    $cache     = new InstrumentationCache($directory);
    $probes    = [0 => new Probe(2, true, 'f', 2, 3, [4])];

    expect($cache->has('key'))->toBeFalse();

    $cache->store('key', new InstrumentationResult('<?php echo 1;', $probes));

    expect($cache->has('key'))->toBeTrue()
        ->and(fileperms($directory) & 0777)->toBe(0777 & ~umask())
        ->and(file_get_contents($directory . '/key.php'))->toBe('<?php echo 1;')
        ->and($cache->probes('key'))->toEqual($probes)
        ->and(array_map('basename', glob($directory . '/*')))->toBe(['key.map', 'key.php']);
});

it('stores in an existing directory', function (): void {
    $directory = TemporaryDirectory::create();
    $cache     = new InstrumentationCache($directory);

    $cache->store('key', new InstrumentationResult('<?php', []));

    expect($cache->probes('key'))->toBe([]);
});

it('fails when a probe map is missing', function (): void {
    $directory = TemporaryDirectory::create();

    expect(fn (): array => (new InstrumentationCache($directory))->probes('missing'))
        ->toThrow(RuntimeException::class, "O mapa de sondas missing não existe no cache {$directory}.");
});

it('does not revive objects other than probes from a map', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/key.map', serialize([new ArrayObject([1])]));

    expect((new InstrumentationCache($directory))->probes('key')[0])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});
