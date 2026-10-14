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

it('creates a private directory, parents included, and accepts it again', function (): void {
    $directory = TemporaryDirectory::create() . '/nested/private';

    Filesystem::privateDirectory($directory);
    Filesystem::privateDirectory($directory);

    expect(is_dir($directory))->toBeTrue()
        ->and(fileperms($directory) & 0o777)->toBe(0o700);
});

it('refuses a directory open to other users', function (): void {
    $directory = TemporaryDirectory::create() . '/shared';
    mkdir($directory, 0o755);

    expect(fn () => Filesystem::privateDirectory($directory))
        ->toThrow(RuntimeException::class, "O diretório {$directory} precisa pertencer ao usuário atual e ser privado: chmod 700 nele resolve.");
});

it('refuses a directory of another user', function (): void {
    expect(fn () => Filesystem::privateDirectory('/usr'))
        ->toThrow(RuntimeException::class, 'O diretório /usr precisa pertencer ao usuário atual e ser privado: chmod 700 nele resolve.');
})->skip(getmyuid() === 0, 'root é dono de tudo');

it('fails when the directory cannot be created', function (): void {
    expect(fn () => Filesystem::privateDirectory('/usr/sonda-cannot-create'))
        ->toThrow(RuntimeException::class, 'Não foi possível criar o diretório /usr/sonda-cannot-create.');
})->skip(getmyuid() === 0, 'root cria onde quiser');

it('refuses a directory other users can enter', function (): void {
    $directory = TemporaryDirectory::create() . '/enterable';
    mkdir($directory, 0o701);

    expect(fn () => Filesystem::privateDirectory($directory))
        ->toThrow(RuntimeException::class, "O diretório {$directory} precisa pertencer ao usuário atual e ser privado: chmod 700 nele resolve.");
});
