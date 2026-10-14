<?php

declare(strict_types=1);
// instrument every PHP file under a dir and lint the output
require getcwd() . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';
$dir = $argv[1];
$out = tempnam(sys_get_temp_dir(), 'covlint'); // unique file created with 0600, not a predictable path
register_shutdown_function(static fn () => @unlink($out));
$ok = $bad = $parseFail = 0;
$inst = new Sonda\Instrumenter((bool) ($argv[2] ?? false));

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    $orig = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');

    if (! str_contains($orig, 'No syntax errors')) {
        continue;
    } // skip files invalid on this PHP

    try {
        $r = $inst->instrument($path, 'x');
    } catch (Throwable $e) {
        $parseFail++;

        continue;
    }
    file_put_contents($out, $r->code);
    $res = shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');

    if (str_contains($res, 'No syntax errors')) {
        $ok++;
    } else {
        $bad++;

        if ($bad <= 10) {
            echo "BAD {$path}\n   ", trim(strtok($res, "\n")), "\n";
        }
    }

    if (substr_count($r->code, "\n") !== substr_count(file_get_contents($path), "\n")) {
        echo "LINES CHANGED {$path}\n";
    }

    foreach (PhpToken::tokenize($r->code) as $token) {
        if ($token->id === T_INLINE_HTML && (str_contains($token->text, 'Sonda') || str_contains($token->text, '$__sonda'))) {
            echo "PROBE IN HTML {$path} line {$token->line}\n";
        }
    }
}
echo "ok={$ok} bad={$bad} parseFail={$parseFail}\n";
