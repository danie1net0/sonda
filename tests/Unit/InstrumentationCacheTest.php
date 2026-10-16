<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe};

it('places the code of a key in its directory', function (): void {
    expect(new InstrumentationCache('/cache')->codePath('abc'))->toBe('/cache/abc.php');
});

it('stores the code and the probe map, creating the directory', function (): void {
    $directory = TemporaryDirectory::create() . '/nested/cache';
    $cache = new InstrumentationCache($directory);
    $probes = [0 => new Probe(2, true, 'f', 2, 3, [4])];

    expect($cache->has('key'))->toBeFalse();

    $cache->store('key', new InstrumentationResult('<?php echo 1;', $probes));

    expect($cache->has('key'))->toBeTrue()
        ->and(fileperms($directory) & 0o777)->toBe(0o700)
        ->and(file_get_contents($directory . '/key.php'))->toBe('<?php echo 1;')
        ->and($cache->probes('key'))->toEqual($probes)
        ->and(array_map(basename(...), glob($directory . '/*')))->toBe(['key.map', 'key.php']);
});

it('stores in an existing directory', function (): void {
    $directory = TemporaryDirectory::create();
    $cache = new InstrumentationCache($directory);

    $cache->store('key', new InstrumentationResult('<?php', []));

    expect($cache->probes('key'))->toBe([]);
});

it('fails when a probe map is missing', function (): void {
    $directory = TemporaryDirectory::create();

    expect(fn (): array => new InstrumentationCache($directory)->probes('missing'))
        ->toThrow(RuntimeException::class, "O arquivo {$directory}/missing.map não pôde ser lido.");
});

it('rejects a map holding something other than probes', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/key.map', serialize([new ArrayObject([1])]));

    expect(fn (): array => new InstrumentationCache($directory)->probes('key'))
        ->toThrow(RuntimeException::class, "O mapa de sondas key está corrompido no cache {$directory}.");
});

it('rejects a map that is not a list of probes', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/key.map', serialize('probes'));

    expect(fn (): array => new InstrumentationCache($directory)->probes('key'))
        ->toThrow(RuntimeException::class, "O mapa de sondas key está corrompido no cache {$directory}.");
});

it('revives nothing but probes from a map, so a foreign object cannot run code', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/key.map', serialize([0 => new RevivalWitness()]));
    RevivalWitness::$revived = false;

    expect(fn (): array => new InstrumentationCache($directory)->probes('key'))
        ->toThrow(RuntimeException::class)
        ->and(RevivalWitness::$revived)->toBeFalse();
});

final class RevivalWitness
{
    public static bool $revived = false;

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        self::$revived = true;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [];
    }
}

it('stores the line map and the analysis copy of a view beside its code', function (): void {
    $directory = TemporaryDirectory::create();
    $cache = new InstrumentationCache($directory);

    $cache->store('view', new InstrumentationResult('<?php probe();', [], [1 => 2, 2 => 2, 3 => 5], '<?php clean();'));

    expect($cache->lineMap('view'))->toBe([1 => 2, 2 => 2, 3 => 5])
        ->and($cache->analysisPath('view'))->toBe($directory . '/view.view.php')
        ->and(file_get_contents($directory . '/view.view.php'))->toBe('<?php clean();')
        ->and(file_get_contents($directory . '/view.php'))->toBe('<?php probe();')
        ->and(array_map(basename(...), glob($directory . '/*')))->toBe(['view.lines', 'view.map', 'view.php', 'view.view.php']);
});

it('has no line map for a file instrumented as it is', function (): void {
    $directory = TemporaryDirectory::create();
    $cache = new InstrumentationCache($directory);
    $cache->store('key', new InstrumentationResult('<?php', []));

    expect($cache->lineMap('key'))->toBeNull()
        ->and(array_map(basename(...), glob($directory . '/*')))->toBe(['key.map', 'key.php']);
});

it('rejects a line map that is not made of line numbers', function (string $contents): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/bad.lines', $contents);

    expect(fn (): ?array => new InstrumentationCache($directory)->lineMap('bad'))
        ->toThrow(RuntimeException::class, "O mapa de linhas bad está corrompido no cache {$directory}.");
})->with([
    'not an array' => [serialize('x')],
    'a string key' => [serialize(['a' => 1])],
    'a string value' => [serialize([1 => 'b'])],
]);

it('writes an empty analysis copy when a view result has none', function (): void {
    $directory = TemporaryDirectory::create();
    $cache = new InstrumentationCache($directory);

    $cache->store('view', new InstrumentationResult('<?php', [], [1 => 1]));

    expect(file_get_contents($cache->analysisPath('view')))->toBe('');
});

it('revives nothing from a line map, so a foreign object cannot run code', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/bad.lines', serialize([1 => new RevivalWitness()]));
    RevivalWitness::$revived = false;

    expect(fn (): ?array => new InstrumentationCache($directory)->lineMap('bad'))
        ->toThrow(RuntimeException::class, "O mapa de linhas bad está corrompido no cache {$directory}.")
        ->and(RevivalWitness::$revived)->toBeFalse();
});
