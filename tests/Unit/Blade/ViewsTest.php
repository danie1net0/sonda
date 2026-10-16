<?php

declare(strict_types=1);

use Sonda\InstrumentationResult;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\Blade\Views;
use Sonda\{Hits, InstrumentationCache, Instrumenter, Settings};

/**
 * A project with two views, settings pointing at it, and the compiled-views directory the
 * bootstrap would hand Laravel through the environment.
 *
 * @return array{root: string, views: string, settings: Settings, compiled: string}
 */
function viewsProject(): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/resources/views/nested', 0777, true);
    mkdir($root . '/runs');
    file_put_contents($root . '/resources/views/home.blade.php', "<h1>Home</h1>\n@if (\$show)\n    {{ \$title }}\n@endif\n");
    file_put_contents($root . '/resources/views/nested/item.blade.php', "<li>{{ \$item }}</li>\n");
    file_put_contents($root . '/resources/views/plain.php', "<?php echo 'not a blade view';\n");
    $settings = new Settings($root . '/vendor/autoload.php', [$root . '/resources/views'], [], [], $root . '/cache', $root . '/runs', true, true, null, [$root . '/resources/views']);

    return ['root' => $root, 'views' => $root . '/resources/views', 'settings' => $settings, 'compiled' => $root . '/runs/views-' . getmypid()];
}

/** The Blade compiler Laravel would build after reading VIEW_COMPILED_PATH, inside a container. */
function laravelLike(string $compiledDirectory): Container
{
    $container = new Container();
    $container->instance('blade.compiler', new BladeCompiler(new Filesystem(), $compiledDirectory));

    return $container;
}

afterEach(function (): void {
    putenv('VIEW_COMPILED_PATH');
    unset($_ENV['VIEW_COMPILED_PATH'], $_SERVER['VIEW_COMPILED_PATH']);
    Container::setInstance();
});

it('points the compiled views of this process at the run directory, through the environment', function (): void {
    ['settings' => $settings, 'compiled' => $compiled] = viewsProject();

    $views = Views::configure($settings, new Instrumenter());

    expect($views)->toBeInstanceOf(Views::class)
        ->and(Views::instance())->toBe($views)
        ->and(fileperms($compiled) & 0o777)->toBe(0o700)
        ->and(getenv('VIEW_COMPILED_PATH'))->toBe($compiled)
        ->and($_ENV['VIEW_COMPILED_PATH'])->toBe($compiled)
        ->and($_SERVER['VIEW_COMPILED_PATH'])->toBe($compiled);
});

it('configures nothing without view directories', function (): void {
    ['settings' => $settings] = viewsProject();
    $withoutViews = new Settings($settings->autoloadPath, $settings->targetDirectories, [], [], $settings->cacheDirectory, $settings->outputDirectory, true, true, null);

    expect(Views::configure($withoutViews, new Instrumenter()))->toBeNull()
        ->and(Views::instance())->toBeNull()
        ->and(getenv('VIEW_COMPILED_PATH'))->toBeFalse();
});

it('compiles and instruments the Blade views into the compiled directory, once, and registers them', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $instrumenter = new Instrumenter();
    $views = Views::configure($settings, $instrumenter);
    $container = laravelLike($compiled);
    $compiler = $container->make('blade.compiler');
    $home = $viewsDirectory . '/home.blade.php';
    $item = $viewsDirectory . '/nested/item.blade.php';

    $key = sha1($home . filemtime($home) . $instrumenter->fingerprint() . 'blade');
    $itemKey = sha1($item . filemtime($item) . $instrumenter->fingerprint() . 'blade');
    $cache = new InstrumentationCache($settings->cacheDirectory);

    $views->prepare($container);
    $files = $views->files();
    unlink($item);
    $views->prepare(laravelLike($compiled));

    expect($files)->toBe([
        substr(sha1($home), 0, 12) => [$home, $key],
        substr(sha1($item), 0, 12) => [$item, $itemKey],
    ])
        ->and($views->files())->toBe($files)
        ->and(file_get_contents($compiler->getCompiledPath($home)))->toBe(file_get_contents($cache->codePath($key)))
        ->toContain('\\' . Hits::class . "::\$current['" . substr(sha1($home), 0, 12) . "']")
        ->and($cache->lineMap($key))->toBe([1 => 1, 2 => 2, 3 => 3, 4 => 3, 5 => 4, 6 => 4])
        ->and(file_get_contents($cache->analysisPath($key)))->toBe($compiler->compileString((string) file_get_contents($home)))
        ->and(fileperms($compiled) & 0o777)->toBe(0o700)
        ->and(filemtime($compiler->getCompiledPath($home)))->toBeGreaterThanOrEqual(filemtime($home));
});

it('prepares the views once per process, whatever container later tests bring', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $views = Views::configure($settings, new Instrumenter());
    $container = laravelLike($compiled);
    $compiledHome = $container->make('blade.compiler')->getCompiledPath($viewsDirectory . '/home.blade.php');

    $views->prepare($container);
    unlink($compiledHome);
    $views->prepare(laravelLike($compiled));

    expect(is_file($compiledHome))->toBeFalse()
        ->and($views->files())->toHaveCount(2);
});

it('keeps the compiled view newer than its template, which Laravel would otherwise compile again without probes', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $home = $viewsDirectory . '/home.blade.php';
    touch($home, time() + 30);
    $views = Views::configure($settings, new Instrumenter());
    $container = laravelLike($compiled);
    $compiler = $container->make('blade.compiler');

    $views->prepare($container);

    expect(filemtime($compiler->getCompiledPath($home)))->toBe(filemtime($home) + 1)
        ->and($compiler->isExpired($home))->toBeFalse();
});

it('reuses the cache of a view already instrumented', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $instrumenter = new Instrumenter();
    $home = $viewsDirectory . '/home.blade.php';
    $key = sha1($home . filemtime($home) . $instrumenter->fingerprint() . 'blade');
    $cache = new InstrumentationCache($settings->cacheDirectory);
    $cache->store($key, new InstrumentationResult('<?php // cached', [], [1 => 1], '<?php // clean'));

    $views = Views::configure($settings, $instrumenter);
    $container = laravelLike($compiled);

    $views->prepare($container);

    expect(file_get_contents($container->make('blade.compiler')->getCompiledPath($home)))->toBe('<?php // cached');
});

it('measures only the views in scope, as .blade.php files', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $home = $viewsDirectory . '/home.blade.php';
    $narrowed = new Settings($settings->autoloadPath, $settings->targetDirectories, [], [$home], $settings->cacheDirectory, $settings->outputDirectory, true, true, null, $settings->viewDirectories);
    $views = Views::configure($narrowed, new Instrumenter());

    $views->prepare(laravelLike($compiled));

    expect(array_column($views->files(), 0))->toBe([$home]);
});

it('leaves out a view that does not compile, saying so', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    file_put_contents($viewsDirectory . '/nested/item.blade.php', "@boom\n");
    $errors = fopen('php://memory', 'w+');
    $views = new Views($settings->scope(), $settings->viewDirectories, new InstrumentationCache($settings->cacheDirectory), $compiled, new Instrumenter(), $errors);
    $container = laravelLike($compiled);
    $container->make('blade.compiler')->directive('boom', static fn (): string => throw new RuntimeException('boom'));

    $views->prepare($container);
    rewind($errors);

    expect(array_column($views->files(), 0))->toBe([$viewsDirectory . '/home.blade.php'])
        ->and(stream_get_contents($errors))->toBe("Sonda: a view {$viewsDirectory}/nested/item.blade.php não compilou e não é medida: boom\n");
});

it('does nothing with a container that has no Blade compiler, and tries again later', function (): void {
    ['settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $views = Views::configure($settings, new Instrumenter());

    $views->prepare(new Container());

    $none = $views->files();
    $views->prepare(laravelLike($compiled));

    expect($none)->toBe([])
        ->and($views->files())->toHaveCount(2);
});

it('creates the subdirectory per worker that Laravel compiles into under parallel testing', function (): void {
    ['views' => $viewsDirectory, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $views = Views::configure($settings, new Instrumenter());
    $container = laravelLike($compiled . '/test_1');

    $views->prepare($container);

    expect(is_dir($compiled . '/test_1'))->toBeTrue()
        ->and($container->make('blade.compiler')->getCompiledPath($viewsDirectory . '/home.blade.php'))->toStartWith($compiled . '/test_1/')
        ->toBeFile()
        ->and($views->files())->toHaveCount(2);
});

it('refuses compiled views outside its directory, which would reach the project cache with probes in them', function (): void {
    ['root' => $root, 'settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $errors = fopen('php://memory', 'w+');
    $views = new Views($settings->scope(), $settings->viewDirectories, new InstrumentationCache($settings->cacheDirectory), $compiled, new Instrumenter(), $errors);

    $views->prepare(laravelLike($compiled . '-twin'));
    rewind($errors);

    expect($views->files())->toBe([])
        ->and(stream_get_contents($errors))->toBe("Sonda: as views compiladas não estão em {$compiled} (VIEW_COMPILED_PATH não chegou à configuração): views não medidas.\n")
        ->and(is_dir($compiled))->toBeFalse();
});

it('prepares from the container class when it is loaded, and skips an unknown one', function (): void {
    ['settings' => $settings, 'compiled' => $compiled] = viewsProject();
    $views = Views::configure($settings, new Instrumenter());
    Container::setInstance(laravelLike($compiled));

    $views->prepareFromApplication('Sonda\\Nope\\Container');
    $none = $views->files();
    $views->prepareFromApplication(Container::class);

    expect($none)->toBe([])
        ->and($views->files())->toHaveCount(2);
});

it('ignores a binding that is not a Blade compiler', function (): void {
    ['settings' => $settings] = viewsProject();
    $views = Views::configure($settings, new Instrumenter());
    $container = new Container();
    $container->instance('blade.compiler', new stdClass());

    $views->prepare($container);

    expect($views->files())->toBe([]);
});
