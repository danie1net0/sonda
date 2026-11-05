<?php

declare(strict_types=1);

// Instrument Sonda with a private copy in another namespace: bootstrap classes must not be
// loaded before their instrumented copies, and the suite resets Sonda\Hits between tests.
// Usage: php tools/self-coverage.php --changed=origin/master --affected

use Sonda\Filesystem;
use Sonda\Cli\SystemProcessRunner;
use Sonda\Report\SourceFiles;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$runtime = sys_get_temp_dir() . '/sonda-self-coverage-' . bin2hex(random_bytes(8));
Filesystem::privateDirectory($runtime);

try {
    $files = SourceFiles::findAll([$root . '/src'], []);

    foreach (['autoload.php', 'bootstrap.php', 'report.php', 'bin/sonda'] as $entry) {
        $files[] = $root . '/' . $entry;
    }

    foreach ($files as $file) {
        $target = $runtime . '/' . substr($file, strlen($root) + 1);
        Filesystem::privateDirectory(dirname($target));
        file_put_contents($target, str_replace('Sonda', 'SondaSelfCoverage', Filesystem::read($file)));
    }

    $command = [];
    $command[] = PHP_BINARY;
    $command[] = $runtime . '/bin/sonda';
    $command[] = 'test';
    $command[] = '--no-includes';

    foreach (array_slice($argv, 1) as $argument) {
        $command[] = $argument;
    }

    $environment = [];
    $environment['XDEBUG_MODE'] = 'off';
    $environment['XDG_CACHE_HOME'] = $runtime . '/cache';
    $status = new SystemProcessRunner(STDIN, STDOUT, STDERR)->run($command, $environment, $root);
} finally {
    Filesystem::removeDirectory($runtime);
}

exit($status);
