<?php

declare(strict_types=1);

// Writes phpunit.xml from phpunit.xml.dist leaving out of the measured source the compatibility
// classes of the php-code-coverage that is not installed: the suite cannot run them, and the
// coverage gate (--min=100) would count them as untested.
//
// php tools/phpunit-for-installed-coverage.php

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
/** @var list<string> $otherVersion */
$otherVersion = require __DIR__ . '/files-of-other-coverage-versions.php';

$document = new DOMDocument();
$document->preserveWhiteSpace = false;
$document->formatOutput = true;
$document->load($root . '/phpunit.xml.dist');

$source = $document->getElementsByTagName('source')->item(0) ?? throw new RuntimeException('phpunit.xml.dist without <source>.');
$exclude = $source->getElementsByTagName('exclude')->item(0) ?? $source->appendChild($document->createElement('exclude'));

foreach ($otherVersion as $file) {
    $exclude->appendChild($document->createElement('file', $file));
}

$document->save($root . '/phpunit.xml');
echo 'phpunit.xml without ' . implode(', ', $otherVersion) . "\n";
