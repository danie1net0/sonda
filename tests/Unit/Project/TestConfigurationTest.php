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
        ->toThrow(RuntimeException::class, "No phpunit.xml or phpunit.xml.dist in {$directory}.");
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
                <file>src/Legacy.php</file>
            </exclude>
        </source>
    </phpunit>
    XML);

    expect(TestConfiguration::load($path))->toEqual(new TestConfiguration(
        $path,
        $directory . '/tests/bootstrap.php',
        [$directory . '/app', $directory . '/src', '/absolute/lib'],
        [$directory . '/app/Providers', $directory . '/src/Legacy.php'],
    ));
});

it('reads a configuration without bootstrap or exclusions', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source><include><directory>src</directory></include></source></phpunit>');

    expect(TestConfiguration::load($path))
        ->bootstrap->toBeNull()
        ->sourceDirectories->toBe([$directory . '/src'])
        ->excludedDirectories->toBe([])
        ->testPaths->toBe([]);
});

it('reads the directories and files of every test suite, relative to the file', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, <<<'XML'
    <phpunit>
        <testsuites>
            <testsuite name="Unit">
                <directory suffix="Test.php">tests/Unit</directory>
                <file>tests/Smoke.php</file>
            </testsuite>
            <testsuite name="Feature">
                <directory> tests/Feature </directory>
            </testsuite>
        </testsuites>
        <source><include><directory>src</directory></include></source>
    </phpunit>
    XML);

    expect(TestConfiguration::load($path)->testPaths)
        ->toBe([$directory . '/tests/Unit', $directory . '/tests/Smoke.php', $directory . '/tests/Feature']);
});

it('keeps the parent folders of a relative path, as PHPUnit does', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, <<<'XML'
    <phpunit bootstrap="../../vendor/autoload.php">
        <testsuites>
            <testsuite name="Platform">
                <directory>../../packages/acme/tests</directory>
                <file>./../Smoke.php</file>
            </testsuite>
        </testsuites>
        <source>
            <include>
                <directory>app</directory>
                <directory>../../packages/acme/src</directory>
                <directory>.hidden</directory>
            </include>
            <exclude>
                <directory>../../packages/acme/src/Generated</directory>
            </exclude>
        </source>
    </phpunit>
    XML);

    expect(TestConfiguration::load($path))->toEqual(new TestConfiguration(
        $path,
        $directory . '/../../vendor/autoload.php',
        [$directory . '/app', $directory . '/../../packages/acme/src', $directory . '/.hidden'],
        [$directory . '/../../packages/acme/src/Generated'],
        [$directory . '/../../packages/acme/tests', $directory . '/../Smoke.php'],
    ));
});

it('reads the memory_limit the tests get, and none without it', function (): void {
    $directory = TemporaryDirectory::create();
    $limited = writeConfiguration($directory, '<phpunit><php><ini name="error_reporting" value="-1"/><ini name="memory_limit" value=" 1G "/></php><source><include><directory>src</directory></include></source></phpunit>');
    $unlimited = writeConfiguration($directory, '<phpunit><php><ini name="error_reporting" value="-1"/></php><source><include><directory>src</directory></include></source></phpunit>', 'other.xml');

    expect(TestConfiguration::load($limited)->memoryLimit)->toBe('1G')
        ->and(TestConfiguration::load($unlimited)->memoryLimit)->toBeNull();
});

it('fails without source directories', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source><include/></source></phpunit>');

    expect(fn (): TestConfiguration => TestConfiguration::load($path))
        ->toThrow(RuntimeException::class, "The file {$path} has no <source><include><directory>: sonda needs it to know what to measure.");
});

it('fails on invalid XML', function (): void {
    $directory = TemporaryDirectory::create();
    $path = writeConfiguration($directory, '<phpunit><source>');

    expect(fn (): TestConfiguration => TestConfiguration::load($path))
        ->toThrow(RuntimeException::class, "The file {$path} is not valid XML.")
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
