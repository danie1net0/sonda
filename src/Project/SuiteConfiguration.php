<?php

declare(strict_types=1);

namespace Sonda\Project;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use Sonda\Filesystem;

use function dirname, str_starts_with;

/**
 * A PHPUnit configuration like the project's, whose only test suite is the paths given: ParaTest
 * takes a single path on its command line, but any number through a configuration. The file is
 * written beside the project's one, so the relative paths in it keep holding.
 */
final class SuiteConfiguration
{
    /**
     * @param list<string> $paths test files and directories, relative to the project or absolute
     *
     * @return string the configuration written
     */
    public static function write(string $configurationPath, array $paths, string $projectRoot): string
    {
        $configurationPath = self::absolute($configurationPath, $projectRoot);
        $document = self::load($configurationPath);
        /** @var DOMElement $root a loaded document has one */
        $root = $document->documentElement;
        $suite = $document->createElement('testsuite');
        $suite->setAttribute('name', 'sonda');

        foreach ($paths as $path) {
            $absolute = realpath(self::absolute($path, $projectRoot));

            if ($absolute === false) {
                throw new InvalidArgumentException("O caminho de teste {$path} não existe.");
            }

            $element = $document->createElement(is_dir($absolute) ? 'directory' : 'file');
            $element->appendChild($document->createTextNode($absolute));

            if (is_dir($absolute)) {
                $element->setAttribute('suffix', self::suffix($document));
            }

            $suite->appendChild($element);
        }

        foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $testsuites) {
            $root->removeChild($testsuites);
        }

        $testsuites = $document->createElement('testsuites');
        $testsuites->appendChild($suite);

        $root->appendChild($testsuites);

        $file = dirname($configurationPath) . '/.sonda-' . bin2hex(random_bytes(4)) . '.phpunit.xml';
        file_put_contents($file, $document->saveXML());

        return $file;
    }

    private static function load(string $path): DOMDocument
    {
        $document = new DOMDocument();
        $usedInternalErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(Filesystem::read($path));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($usedInternalErrors);
        }

        if (! $loaded) {
            throw new RuntimeException("O arquivo {$path} não é um XML válido.");
        }

        return $document;
    }

    /** The suffix of the project's own suites, which tells its test files apart; PHPUnit's default otherwise. */
    private static function suffix(DOMDocument $document): string
    {
        foreach ($document->getElementsByTagName('testsuite') as $testsuite) {
            foreach ($testsuite->getElementsByTagName('directory') as $directory) {
                if ($directory->hasAttribute('suffix')) {
                    return $directory->getAttribute('suffix');
                }
            }
        }

        return 'Test.php';
    }

    private static function absolute(string $path, string $projectRoot): string
    {
        return str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
    }
}
