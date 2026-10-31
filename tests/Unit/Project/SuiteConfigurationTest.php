<?php

declare(strict_types=1);

use Sonda\Project\SuiteConfiguration;

/** @return array{root: string, configuration: string} a project with a phpunit.xml of its own */
function configuredProject(string $testsuites = '<testsuites><testsuite name="Unit"><directory suffix=".php">tests/Unit</directory></testsuite><testsuite name="Feature"><directory>tests/Feature</directory></testsuite></testsuites>'): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/tests/Unit', 0777, true);
    mkdir($root . '/tests/Feature');
    mkdir($root . '/src');
    file_put_contents($root . '/tests/Feature/ATest.php', '<?php');
    file_put_contents($root . '/phpunit.xml', "<?xml version=\"1.0\"?>\n<phpunit bootstrap=\"vendor/autoload.php\" colors=\"true\">\n    {$testsuites}\n    <source><include><directory>src</directory></include></source>\n    <php><env name=\"APP_ENV\" value=\"testing\"/></php>\n</phpunit>\n");

    return ['root' => $root, 'configuration' => $root . '/phpunit.xml'];
}

it('writes a configuration beside the project one with the paths given as its only suite, directories with the project suffix', function (): void {
    ['root' => $root, 'configuration' => $configuration] = configuredProject();

    $written = SuiteConfiguration::write('phpunit.xml', ['tests/Unit', $root . '/tests/Feature/ATest.php'], $root);
    $xml = simplexml_load_file($written);

    expect($written)->toMatch('#^' . preg_quote($root, '#') . '/\\.sonda-[0-9a-f]{8}\\.phpunit\\.xml$#')
        ->and($xml)->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $xml['bootstrap'])->toBe('vendor/autoload.php')
        ->and((string) $xml['colors'])->toBe('true')
        ->and($xml->xpath('/phpunit/testsuites'))->toHaveCount(1)
        ->and($xml->xpath('/phpunit/testsuites/testsuite'))->toHaveCount(1)
        ->and((string) $xml->testsuites->testsuite['name'])->toBe('sonda')
        ->and((string) $xml->testsuites->testsuite->directory)->toBe($root . '/tests/Unit')
        ->and((string) $xml->testsuites->testsuite->directory['suffix'])->toBe('.php')
        ->and((string) $xml->testsuites->testsuite->file)->toBe($root . '/tests/Feature/ATest.php')
        ->and((string) $xml->source->include->directory)->toBe('src')
        ->and((string) $xml->php->env['name'])->toBe('APP_ENV')
        ->and(file_get_contents($configuration))->toContain('name="Unit"');
});

it('falls back to the suffix PHPUnit assumes when the project suites name none', function (): void {
    ['root' => $root] = configuredProject('<testsuites><testsuite name="Feature"><directory>tests/Feature</directory></testsuite></testsuites>');

    $xml = simplexml_load_file(SuiteConfiguration::write($root . '/phpunit.xml', ['tests/Unit'], $root));

    expect($xml)->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $xml->testsuites->testsuite->directory['suffix'])->toBe('Test.php');
});

it('adds the suite to a configuration without suites', function (): void {
    ['root' => $root] = configuredProject('');

    $xml = simplexml_load_file(SuiteConfiguration::write('phpunit.xml', ['tests/Unit'], $root));

    expect($xml)->toBeInstanceOf(SimpleXMLElement::class)
        ->and($xml->xpath('/phpunit/testsuites/testsuite'))->toHaveCount(1);
});

it('refuses a test path that does not exist', function (): void {
    ['root' => $root] = configuredProject();

    expect(fn (): string => SuiteConfiguration::write('phpunit.xml', ['tests/Unit', 'tests/None'], $root))
        ->toThrow(InvalidArgumentException::class, 'The test path tests/None does not exist.');
});

it('refuses a configuration that is not XML', function (): void {
    ['root' => $root] = configuredProject();
    file_put_contents($root . '/phpunit.xml', 'not xml');

    expect(fn (): string => SuiteConfiguration::write('phpunit.xml', ['tests/Unit'], $root))
        ->toThrow(RuntimeException::class, "The file {$root}/phpunit.xml is not valid XML.")
        ->and(libxml_use_internal_errors())->toBeFalse()
        ->and(libxml_get_errors())->toBe([]);
});

it('leaves no libxml error behind when the caller collects them', function (): void {
    ['root' => $root] = configuredProject();
    file_put_contents($root . '/phpunit.xml', '<phpunit><testsuites>');
    libxml_use_internal_errors(true);

    try {
        expect(fn (): string => SuiteConfiguration::write('phpunit.xml', ['tests/Unit'], $root))->toThrow(RuntimeException::class)
            ->and(libxml_get_errors())->toBe([])
            ->and(libxml_use_internal_errors())->toBeTrue();
    } finally {
        libxml_use_internal_errors(false);
    }
});

it('takes the suffix only from a directory of a suite, not from the source', function (): void {
    ['root' => $root] = configuredProject('<testsuites><testsuite name="Feature"><directory>tests/Feature</directory></testsuite></testsuites>');
    file_put_contents($root . '/phpunit.xml', str_replace('<directory>src</directory>', '<directory suffix=".inc">src</directory>', (string) file_get_contents($root . '/phpunit.xml')));

    $xml = simplexml_load_file(SuiteConfiguration::write('phpunit.xml', ['tests/Unit'], $root));

    expect($xml)->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $xml->testsuites->testsuite->directory['suffix'])->toBe('Test.php');
});
