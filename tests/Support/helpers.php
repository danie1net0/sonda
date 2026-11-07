<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;
use SebastianBergmann\CodeCoverage\StaticAnalysis\{Registry, SourceAnalyser};
use Sonda\Report\Compat\CoverageApis;
use Sonda\{Filesystem, Hits, InstrumentingVisitor, Probe, SourceEditor, TestRecords};
use PhpParser\{NodeTraverser, ParserFactory};
use PHPUnit\Event\Code\{Test, TestDox, TestMethod};
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Metadata\MetadataCollection;

foreach (glob(__DIR__ . '/../Fixtures/Sizes/*.php') ?: [] as $sizedTest) {
    require_once $sizedTest;
}

require_once __DIR__ . '/../Fixtures/RevivalWitness.php';
require_once __DIR__ . '/compat.php';

final class TemporaryDirectory
{
    /** @var list<string> */
    public static array $created = [];

    public static function create(): string
    {
        $directory = sys_get_temp_dir() . '/sonda-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);

        self::$created[] = $directory;

        return (string) realpath($directory);
    }

    public static function remove(string $directory): void
    {
        Filesystem::removeDirectory($directory);
    }
}

/**
 * Runs the visitor over a piece of code and applies its edits.
 *
 * @return array{0: string, 1: array<int, Probe>}
 */
function instrumentCode(string $code, bool $strict = false, bool $fast = false, string $path = '/project/src/File.php', string $mainScope = '{main}'): array
{
    $parser = new ParserFactory()->createForHostVersion();
    $ast = (array) $parser->parse($code);

    $visitor = new InstrumentingVisitor($parser->getTokens(), $code, 'f', $path, $strict, $fast, $mainScope);
    $visitor->instrumentTopLevel($ast);
    new NodeTraverser($visitor)->traverse($ast);

    $result = [];
    $result[] = SourceEditor::apply($code, $visitor->edits());
    $result[] = $visitor->probes();

    return $result;
}

/**
 * Expands probe placeholders of an expected instrumented source:
 * {pN} statement probe, {oN} opening of a wrapped expression, {c} its closing,
 * {sN:true} or {sN:false} the opening of a short-circuit pass-through (closed by a plain `)`),
 * {lN} statement probe through the local reference, {LN} wrap opening through it,
 * {ref} the local reference binding.
 */
function expected(string $template): string
{
    return (string) preg_replace_callback(
        '/\{(p|o|l|L)(\d+)\}|\{s(\d+):(true|false)\}|\{c\}|\{ref\}/',
        static fn (array $match): string => match ($match[0]) {
            '{c}' => ') : null)',
            '{ref}' => '$__sonda = &\\' . Hits::class . '::$current[\'f\'];',
            default => match ($match[1]) {
                '' => '\\' . Hits::class . "::shortCircuit('f', " . $match[3] . ', ' . $match[4] . ', ',
                'p' => '\\' . Hits::class . '::$current[\'f\'][' . $match[2] . ']=1;',
                'o' => '((\\' . Hits::class . '::$current[\'f\'][' . $match[2] . ']=1) ? (',
                'l' => '$__sonda[' . $match[2] . ']=1;',
                default => '(($__sonda[' . $match[2] . ']=1) ? (',
            },
        },
        $template,
    );
}

/** A test method as PHPUnit's events describe it. */
function testMethod(string $className, string $methodName = 'test_it', string $file = '/project/tests/ATest.php'): TestMethod
{
    return new TestMethod($className, $methodName, $file, 1, new TestDox($className, $methodName, $methodName), MetadataCollection::fromArray([]), TestDataCollection::fromArray([]));
}

/**
 * A PHPUnit test event about a test, without the telemetry the subscribers never read.
 *
 * @template TEvent of object
 *
 * @param class-string<TEvent> $eventClass
 *
 * @return TEvent
 */
function testEvent(string $eventClass, Test $test): object
{
    $event = new ReflectionClass($eventClass)->newInstanceWithoutConstructor();
    new ReflectionProperty($eventClass, 'test')->setValue($event, $test);

    return $event;
}

/**
 * Whether the installed php-code-coverage is 12 (PHPUnit 12, Pest 4) or later, which keeps its data
 * in objects, not 11 (PHPUnit 11, Pest 3).
 */
function onCoverage12(): bool
{
    return interface_exists(SourceAnalyser::class);
}

/** Whether the installed php-code-coverage is 14 (PHPUnit 13, Pest 5). */
function onCoverage14(): bool
{
    return class_exists(Registry::class);
}

/**
 * The branches of the processed data of a function, whatever php-code-coverage keeps it in, each
 * with the ids of the tests that took it: php-code-coverage 14 keeps their indexes in the data.
 *
 * @return list<array{op_start: int, op_end: int, line_start: int, line_end: int, hit: list<string>, out: array<int, int>, out_hit: array<int, int>}>
 */
function branchesOf(mixed $function, ?ProcessedCodeCoverageData $data = null): array
{
    $branches = [];

    foreach (CoverageApis::current()->branches($function) as $branch) {
        $branch['hit'] = testIdsOf($branch['hit'], $data);
        $branches[] = $branch;
    }

    return $branches;
}

/**
 * The line data of a report, each executed line with the ids of the tests that ran it.
 *
 * @return array<string, array<int, list<string>|null>>
 */
function lineTestsOf(ProcessedCodeCoverageData $data): array
{
    $lines = [];

    foreach ($data->lineCoverage() as $file => $fileLines) {
        foreach ($fileLines as $line => $tests) {
            $lines[$file][$line] = $tests === null ? null : testIdsOf($tests, $data);
        }
    }

    return $lines;
}

/**
 * The ids of the tests of a line or a branch: php-code-coverage 11 and 12 keep the ids, 14 a map
 * of test index to hits.
 *
 * @param array<mixed> $tests
 *
 * @return list<string>
 */
function testIdsOf(array $tests, ?ProcessedCodeCoverageData $data): array
{
    if (! $data instanceof ProcessedCodeCoverageData || ! method_exists($data, 'testIds')) {
        /** @var list<string> $tests */
        return $tests;
    }

    $ids = $data->testIds();

    return array_map(static fn (int|string $index): string => $ids[$index], array_keys($tests));
}

/**
 * The data and the tests of a `.cov` file, whatever php-code-coverage wrote it.
 *
 * @return array{0: ProcessedCodeCoverageData, 1: array<string, array{size: string, status: string, time: float}>}
 */
function covFile(string $path): array
{
    if (class_exists(Unserializer::class)) {
        $serialized = new Unserializer()->unserialize($path);
        $data = $serialized['codeCoverage'];

        // The serializer keeps the paths relative to the directory they share.
        foreach ($data->coveredFiles() as $file) {
            $data->renameFile($file, rtrim($serialized['basePath'], '/') . '/' . $file);
        }

        $result = [];
        $result[] = $data;
        $result[] = $serialized['testResults'];

        return $result;
    }

    $coverage = require $path;

    if (! $coverage instanceof CodeCoverage) {
        throw new RuntimeException("The file {$path} does not hold a report.");
    }

    $result = [];
    $result[] = $coverage->getData();
    $result[] = $coverage->getTests();

    return $result;
}

/**
 * Test records built from what each test hit, as the tests of a suite leave them.
 *
 * @param array<string, array{0: string, 1: 'small'|'medium'|'large'|'unknown', 2: bool, 3: float, 4: array<string, string>}> $tests test id => [test file, size, passed, seconds, file id => probe ids packed]
 */
function testRecords(array $tests): TestRecords
{
    $records = new TestRecords();

    foreach ($tests as $testId => [$testFile, $size, $passed, $seconds, $packedHits]) {
        $records->add($testId, $testFile, $size, $passed, $seconds, $packedHits);
    }

    return $records;
}

/**
 * What each test hit, read back from test records in the shape testRecords() takes.
 *
 * @return array<string, array{0: string, 1: 'small'|'medium'|'large'|'unknown', 2: bool, 3: float, 4: array<string, string>}>
 */
function recordsOf(TestRecords $records): array
{
    $tests = [];

    foreach ($records->records as $testId => [$testFile, $size, $passed, $seconds, $packedPairIds]) {
        $packedHits = [];

        foreach ($records->pairsOf($packedPairIds) as [$fileId, $packed]) {
            $packedHits[$fileId] = $packed;
        }

        $tests[$testId] = [$testFile, $size, $passed, $seconds, $packedHits];
    }

    return $tests;
}
