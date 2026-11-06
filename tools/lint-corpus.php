<?php

declare(strict_types=1);
// instrument every PHP file under a dir and lint the output
require getcwd() . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';
$directory = $argv[1];
$output = tempnam(sys_get_temp_dir(), 'covlint'); // unique file created with 0600, not a predictable path
register_shutdown_function(static fn () => @unlink($output));
$valid = $invalid = $parseFailures = 0;
$instrumenter = new Sonda\Instrumenter((bool) ($argv[2] ?? false));

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $originalLint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');

    if (! str_contains($originalLint, 'No syntax errors')) {
        continue;
    } // skip files invalid on this PHP

    try {
        $result = $instrumenter->instrument($path, 'x');
    } catch (Throwable) {
        $parseFailures++;

        continue;
    }

    file_put_contents($output, $result->code);
    $lint = shell_exec('php -l ' . escapeshellarg($output) . ' 2>&1');

    $isValid = str_contains($lint, 'No syntax errors');
    $valid += (int) $isValid;

    if (! $isValid) {
        $invalid++;
    }

    if (! $isValid && $invalid <= 10) {
        echo "BAD {$path}\n   ", trim(strtok($lint, "\n")), "\n";
    }

    if (substr_count($result->code, "\n") !== substr_count(file_get_contents($path), "\n")) {
        echo "LINES CHANGED {$path}\n";
    }

    foreach (PhpToken::tokenize($result->code) as $token) {
        if ($token->id === T_INLINE_HTML && (str_contains($token->text, 'Sonda') || str_contains($token->text, '$__sonda'))) {
            echo "PROBE IN HTML {$path} line {$token->line}\n";
        }
    }
}

echo "ok={$valid} bad={$invalid} parseFail={$parseFailures}\n";
