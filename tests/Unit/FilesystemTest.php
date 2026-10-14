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
