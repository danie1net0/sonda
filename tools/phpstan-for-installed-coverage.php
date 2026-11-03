<?php

declare(strict_types=1);

// PHPStan analyses against the installed php-code-coverage: the compatibility files of the other
// versions name classes and types it does not have. The suite runs them under each version in CI.

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
/** @var list<string> $otherVersion */
$otherVersion = require __DIR__ . '/files-of-other-coverage-versions.php';

$parameters = [];
// Left out of the analysis only: their classes stay known, as the lookup in CoverageApis names them.
$parameters['excludePaths'] = ['analyse' => array_map(static fn (string $file): string => $root . '/' . $file, $otherVersion)];
// php-code-coverage 14 asks its driver for a non-empty version; Version::of gives a version, a
// commit or "unknown", which PHPStan only knows as a string. Older versions ask for no version.
$parameters['ignoreErrors'] = [['identifier' => 'return.type', 'path' => $root . '/src/Report/CoverageDriver.php', 'reportUnmatched' => false]];

return ['parameters' => $parameters];
