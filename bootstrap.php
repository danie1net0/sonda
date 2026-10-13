<?php declare(strict_types=1);

// PHPUnit bootstrap: COV_TARGET (dir to instrument), COV_OUT (hits file), COV_CACHE, COV_STRICT
require __DIR__ . '/src/R.php';
require __DIR__ . '/src/Instrumenter.php';
require __DIR__ . '/src/PhpUnitExtension.php';

$composer = require getenv('COV_AUTOLOAD');
$target   = rtrim(realpath(getenv('COV_TARGET')), '/') . '/';
$cacheDir = getenv('COV_CACHE');
$strict   = getenv('COV_STRICT') === '1';
$fast     = getenv('COV_FAST') === '1';
@mkdir($cacheDir, 0777, true);

$GLOBALS['__cov_files'] = [];

spl_autoload_register(static function (string $class) use ($composer, $target, $cacheDir, $strict, $fast): void {
    $file = $composer->findFile($class);
    if ($file === false) {
        return;
    }
    $file = realpath($file);
    if (!str_starts_with($file, $target)) {
        return;
    }

    $fid    = substr(sha1($file), 0, 12);
    $key    = sha1($file . filemtime($file) . (int) $strict . (int) $fast . filemtime(__DIR__ . '/src/Instrumenter.php'));
    $cached = $cacheDir . '/' . $key . '.php';

    if (!is_file($cached)) {
        $result = (new \CovProto\Instrumenter($strict, $fast))->instrument($file, $fid);
        file_put_contents($cached . '.tmp', $result['code']);
        rename($cached . '.tmp', $cached);
        file_put_contents($cacheDir . '/' . $key . '.map', serialize($result['probes']));
    }

    $GLOBALS['__cov_files'][$fid] = [$file, $key];
    (static function () use ($cached) { include $cached; })();
}, true, true);

if (getenv('COV_CHAIN')) {
    require getenv('COV_CHAIN');
}

register_shutdown_function(static function () use ($cacheDir): void {
    file_put_contents(getenv('COV_OUT'), serialize([
        'cache' => $cacheDir,
        'files' => $GLOBALS['__cov_files'],
        'hits'  => \CovProto\R::collected(),
    ]));
});
