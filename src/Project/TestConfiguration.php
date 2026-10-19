<?php

declare(strict_types=1);

namespace Sonda\Project;

use RuntimeException;
use SimpleXMLElement;
use Sonda\Filesystem;

use function dirname, str_starts_with, trim;

/** What sonda needs from the project's phpunit.xml: the bootstrap and the `<source>` directories. */
final readonly class TestConfiguration
{
    /**
     * @param list<string> $sourceDirectories
     * @param list<string> $excludedDirectories
     * @param list<string> $testPaths           the directories and files of the test suites
     */
    public function __construct(
        public string $path,
        public ?string $bootstrap,
        public array $sourceDirectories,
        public array $excludedDirectories,
        public array $testPaths = [],
    ) {
    }

    /** `phpunit.xml` wins over `phpunit.xml.dist`, as in PHPUnit. */
    public static function find(string $projectRoot): string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            if (is_file($projectRoot . '/' . $name)) {
                return $projectRoot . '/' . $name;
            }
        }

        throw new RuntimeException("Nenhum phpunit.xml ou phpunit.xml.dist em {$projectRoot}.");
    }

    public static function load(string $path): self
    {
        $contents = Filesystem::read($path);
        $usedInternalErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contents);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($usedInternalErrors);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("O arquivo {$path} não é um XML válido.");
        }

        $base = dirname($path);
        $bootstrap = isset($xml['bootstrap']) ? self::absolute((string) $xml['bootstrap'], $base) : null;
        $sources = self::directories($xml, '/phpunit/source/include/directory', $base);

        if ($sources === []) {
            throw new RuntimeException("O arquivo {$path} não tem <source><include><directory>: o sonda precisa dele para saber o que medir.");
        }

        return new self(
            $path,
            $bootstrap,
            $sources,
            self::directories($xml, '/phpunit/source/exclude/directory', $base),
            self::directories($xml, '/phpunit/testsuites/testsuite/directory | /phpunit/testsuites/testsuite/file', $base),
        );
    }

    /** @return list<string> */
    private static function directories(SimpleXMLElement $xml, string $query, string $base): array
    {
        $directories = [];

        foreach ($xml->xpath($query) ?: [] as $element) {
            $directories[] = self::absolute(trim((string) $element), $base);
        }

        return $directories;
    }

    private static function absolute(string $path, string $base): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $base . '/' . ltrim($path, './');
    }
}
