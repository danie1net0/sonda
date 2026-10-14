<?php

declare(strict_types=1);

use Sonda\Project\TestConfiguration;

function writeConfiguration(string $directory, string $xml, string $name = 'phpunit.xml'): string
{
    $path = $directory . '/' . $name;
    file_put_contents($path, $xml);

    return $path;
}

it('prefers phpunit.xml over phpunit.xml.dist', function (): void {
    $directory = TemporaryDirectory::create();
    writeConfiguration($directory, '<phpunit/>', 'phpunit.xml.dist');

    expect(TestConfiguration::find($directory))->toBe($directory . '/phpunit.xml.dist');

    writeConfiguration($directory, '<phpunit/>');

    expect(TestConfiguration::find($directory))->toBe($directory . '/phpunit.xml');
});

it('fails when the project has no configuration file', function (): void {
    $directory = TemporaryDirectory::create();

    expect(fn (): string => TestConfiguration::find($directory))
        ->toThrow(RuntimeException::class, "Nenhum phpunit.xml ou phpunit.xml.dist em {$directory}.");
});

it('reads the bootstrap and the source directories, relative to the file', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, <<<'XML'
    <?xml version="1.0"?>
    <phpunit bootstrap="tests/bootstrap.php">
        <source>
            <include>
                <directory suffix=".php">./app</directory>
                <directory>src</directory>
                <directory>/absolute/lib</directory>
            </include>
            <exclude>
                <directory>app/Providers</directory>
            </exclude>
        </source>
    </phpunit>
    XML);

    expect(TestConfiguration::load($path))->toEqual(new TestConfiguration(
        $path,
        $directory . '/tests/bootstrap.php',
        [$directory . '/app', $directory . '/src', '/absolute/lib'],
        [$directory . '/app/Providers'],
    ));
});

it('reads a configuration without bootstrap or exclusions', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source><include><directory>src</directory></include></source></phpunit>');

    expect(TestConfiguration::load($path))
        ->bootstrap->toBeNull()
        ->sourceDirectories->toBe([$directory . '/src'])
        ->excludedDirectories->toBe([]);
});

it('fails without source directories', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source><include/></source></phpunit>');

    expect(fn (): TestConfiguration => TestConfiguration::load($path))
        ->toThrow(RuntimeException::class, "O arquivo {$path} não tem <source><include><directory>: o sonda precisa dele para saber o que medir.");
});

it('fails on invalid XML', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source>');

    expect(fn (): TestConfiguration => TestConfiguration::load($path))
        ->toThrow(RuntimeException::class, "O arquivo {$path} não é um XML válido.")
        ->and(libxml_use_internal_errors())->toBeFalse()
        ->and(libxml_get_errors())->toBe([]);
});

it('trims the text of the directories', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, "<phpunit><source><include><directory>\n    app\n  </directory></include></source></phpunit>");

    expect(TestConfiguration::load($path))->sourceDirectories->toBe([$directory . '/app']);
});

it('leaves no libxml error behind when the caller collects them', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source>');
    libxml_use_internal_errors(true);

    try {
        expect(fn (): TestConfiguration => TestConfiguration::load($path))->toThrow(RuntimeException::class)
            ->and(libxml_get_errors())->toBe([])
            ->and(libxml_use_internal_errors())->toBeTrue();
    } finally {
        libxml_use_internal_errors(false);
    }
});
