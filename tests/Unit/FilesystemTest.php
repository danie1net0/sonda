<?php

declare(strict_types=1);

use Sonda\Filesystem;

it('reads a file', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'conteúdo');

    expect(Filesystem::read($path))->toBe('conteúdo');
});

it('fails to read a missing file', function (): void {
    expect(fn (): string => Filesystem::read('/missing/file.txt'))
        ->toThrow(RuntimeException::class, 'O arquivo /missing/file.txt não pôde ser lido.');
});

it('removes a directory with everything in it', function (): void {
    $directory = TemporaryDirectory::create() . '/tree';
    mkdir($directory . '/nested/deeper', 0777, true);
    file_put_contents($directory . '/nested/deeper/file.txt', 'x');
    file_put_contents($directory . '/top.txt', 'y');

    Filesystem::removeDirectory($directory);
    Filesystem::removeDirectory($directory);

    expect(is_dir($directory))->toBeFalse();
});
