<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Sonda\{Hits, Instrumenter, InstrumentingAutoloader, Run, Scope, Settings};

/**
 * A project with one class in the target directory and one outside it, under a namespace
 * unique to the test (classes cannot be declared twice in the test process).
 *
 * @return array{directory: string, namespace: string, loader: ClassLoader}
 */
function fixtureProject(): array
{
    $directory = TemporaryDirectory::create();
    $namespace = 'Fixture' . bin2hex(random_bytes(6));
    mkdir($directory . '/app');
    mkdir($directory . '/lib');

    file_put_contents($directory . '/app/Order.php', <<<PHP
    <?php

    namespace {$namespace}\\App;

    final class Order
    {
        public function total(int \$quantity): int
        {
            return \$quantity * 2;
        }

        public function file(): string
        {
            return __FILE__;
        }
    }
    PHP);
    file_put_contents($directory . '/lib/Helper.php', "<?php\n\nnamespace {$namespace}\\Lib;\n\nfinal class Helper\n{\n}\n");

    $loader = new ClassLoader();
    $loader->addPsr4($namespace . '\\App\\', $directory . '/app');
    $loader->addPsr4($namespace . '\\Lib\\', $directory . '/lib');

    $result = [];
    $result['directory'] = $directory;
    $result['namespace'] = $namespace;
    $result['loader'] = $loader;

    return $result;
}

it('loads a class of the target directory from its instrumented copy', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());
    $class = $namespace . '\App\Order';

    $autoloader->load($loader, $class);
    $order = new $class();
    $total = $order->total(3);
    $filePath = $order->file();

    $run = $autoloader->run();
    $fileId = array_key_first($run->files);

    // A hash prefix made only of digits comes back from the array as an int key.
    expect((string) $fileId)->toBe(substr(sha1($directory . '/app/Order.php'), 0, 12))
        ->and($total)->toBe(6)
        ->and($filePath)->toBe($directory . '/app/Order.php')
        ->and(new ReflectionClass($class)->getFileName())->toBe($directory . '/app/Order.php')
        ->and($run->files)->toBe([$fileId => [$directory . '/app/Order.php', $run->files[$fileId][1]]])
        ->and(is_file($directory . '/cache/' . $run->files[$fileId][1] . '.php'))->toBeTrue()
        ->and(Hits::$current[$fileId])->toBe([0 => 1, 1 => 1])
        ->and($run->hits)->toBe(Hits::collected())
        ->and($run->tests->records)->toBe([])
        ->and($run->cacheDirectory)->toBe($directory . '/cache');
});

it('hands the tests recorded so far to the run', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    Hits::finishTest('Tests\\OrderTest::test_total', '/tests/OrderTest.php', 'unknown');

    $run = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter())->run();

    expect($run->tests)->toBe(Hits::tests())
        ->and($run->tests->records)->toHaveKey('Tests\\OrderTest::test_total');

    Hits::$problems['broken'] = true;

    expect(new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter())->run()->problems)->toBe(['broken']);
});

it('reuses the instrumented copy already in the cache', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $instrumenter = new Instrumenter();
    $path = $directory . '/app/Order.php';
    $key = sha1($path . filemtime($path) . $instrumenter->fingerprint());
    mkdir($directory . '/cache');
    file_put_contents($directory . "/cache/{$key}.php", "<?php\n\nnamespace {$namespace}\\App;\n\nfinal class Order\n{\n    public const CACHED = true;\n}\n");

    new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', $instrumenter)->load($loader, $namespace . '\App\Order');

    expect(constant($namespace . '\App\Order::CACHED'))->toBeTrue();
});

it('leaves classes outside the target directory to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($loader, $namespace . '\Lib\Helper');
    $autoloader->load($loader, $namespace . '\Missing\Thing');

    expect(class_exists($namespace . '\Lib\Helper', false))->toBeFalse()
        ->and($autoloader->run()->files)->toBe([])
        ->and(is_dir($directory . '/cache'))->toBeFalse();
});

it('registers itself before the other autoloaders', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->register($loader);

    $first = spl_autoload_functions()[0];
    spl_autoload_unregister($first);

    expect($first)->toBeInstanceOf(Closure::class)
        ->and(new ReflectionFunction($first)->getClosureThis())->toBe($autoloader)
        ->and(fileperms($directory . '/cache') & 0o777)->toBe(0o700);
});

it('refuses to register over a cache directory open to other users', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/cache', 0o755);
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    expect(fn () => $autoloader->register($loader))
        ->toThrow(RuntimeException::class, "The directory {$directory}/cache must belong to the current user and be private: chmod 700 on it fixes that.");
});

it('reports a run with the probes collected so far', function (): void {
    Hits::$perTest = true;
    Hits::$accumulated = ['abc' => [1 => 1]];
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();

    $run = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter())->run();

    expect($run)->toEqual(new Run($directory . '/cache', [], ['abc' => [1 => 1]]));
});

it('does not take a directory that only shares a prefix with the target as part of it', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/application');
    file_put_contents($directory . '/application/Invoice.php', "<?php\n\nnamespace {$namespace}\\Application;\n\nfinal class Invoice\n{\n}\n");
    $loader->addPsr4($namespace . '\\Application\\', $directory . '/application');
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($loader, $namespace . '\Application\Invoice');

    expect($autoloader->run()->files)->toBe([]);
});

it('leaves a class whose mapped file was deleted to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $loader->addClassMap([$namespace . '\App\Deleted' => $directory . '/app/Deleted.php']);
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($loader, $namespace . '\App\Deleted');

    expect($autoloader->run()->files)->toBe([]);
});

it('leaves the excluded directories and the files out of a narrowed scope to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/app/Providers');
    file_put_contents($directory . '/app/Providers/AppProvider.php', "<?php\n\nnamespace {$namespace}\\App\\Providers;\n\nfinal class AppProvider\n{\n}\n");
    $scope = new Scope([$directory . '/app'], [$directory . '/app/Providers'], [$directory . '/app/Providers/AppProvider.php']);
    $autoloader = new InstrumentingAutoloader($scope, $directory . '/cache', new Instrumenter());

    $autoloader->load($loader, $namespace . '\App\Providers\AppProvider');
    $autoloader->load($loader, $namespace . '\App\Order');

    expect($autoloader->run()->files)->toBe([]);
});

it("loads a file of the scope by its path from its instrumented copy, as one of Composer's files", function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    $function = 'helper' . bin2hex(random_bytes(6));
    file_put_contents($directory . '/app/helpers.php', "<?php\n\nfunction {$function}(int \$x): int\n{\n    return \$x + 1;\n}\n");
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->loadFile($directory . '/app/helpers.php');

    $result = $function(1);

    $run = $autoloader->run();
    $fileId = array_key_first($run->files);

    expect($result)->toBe(2)
        ->and($run->files)->toBe([$fileId => [$directory . '/app/helpers.php', $run->files[$fileId][1]]])
        ->and($run->hits[$fileId])->not->toBe([]);
});

it('hands the instrumented copy of a PHP file of the scope that code includes by its path, and counts it as loaded', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    file_put_contents($directory . '/app/routes.php', "<?php\n\nreturn 'routes';\n");
    mkdir($directory . '/app/views');
    file_put_contents($directory . '/app/views/home.blade.php', '<h1>Home</h1>');
    file_put_contents($directory . '/app/notes.txt', 'notes');
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app'], [$directory . '/app/views']), $directory . '/cache', new Instrumenter());
    $autoloader->register($loader);

    $copy = $autoloader->copyFor($directory . '/app/../app/routes.php');
    $fileId = substr(sha1($directory . '/app/routes.php'), 0, 12);

    expect($copy)->toStartWith($directory . '/cache/')
        ->and(include $copy)->toBe('routes')
        ->and($autoloader->run()->files)->toBe([$fileId => [$directory . '/app/routes.php', basename((string) $copy, '.php')]])
        ->and($autoloader->copyFor($directory . '/lib/Helper.php'))->toBeNull()
        ->and($autoloader->copyFor($directory . '/app/notes.txt'))->toBeNull()
        ->and($autoloader->copyFor($directory . '/app/views/home.blade.php'))->toBeNull()
        ->and($autoloader->copyFor($directory . '/app/missing.php'))->toBeNull();
});

it('leaves a Blade template of the scope to the views, which instrument its compiled code', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    file_put_contents($directory . '/app/home.blade.php', '<?php echo 1;');
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());
    $autoloader->register($loader);

    expect($autoloader->copyFor($directory . '/app/home.blade.php'))->toBeNull();
});

it('registers itself once, however many times it is asked', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());
    $before = count(spl_autoload_functions());

    $autoloader->register($loader);
    $autoloader->register($loader);

    $registered = count(spl_autoload_functions()) - $before;
    spl_autoload_unregister(spl_autoload_functions()[0]);

    expect($registered)->toBe(1);
});

it('names the files of the scope that PHP included without probes', function (): void {
    ['directory' => $directory] = fixtureProject();
    file_put_contents($directory . '/app/home.blade.php', '<h1>Home</h1>');
    file_put_contents($directory . '/app/notes.txt', 'notes');
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $uninstrumented = $autoloader->uninstrumented([
        $directory . '/lib/Helper.php',
        $directory . '/app/../app/Order.php',
        $directory . '/app/home.blade.php',
        $directory . '/app/notes.txt',
        $directory . '/app/Missing.php',
    ]);

    expect($uninstrumented)->toBe([$directory . '/app/Order.php'])
        ->and($autoloader->uninstrumented([]))->toBe([])
        ->and($autoloader->run()->files)->toBe([]);
});

it('does not take for uninstrumented the files it loaded itself, which PHP lists under their own names', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    file_put_contents($directory . '/app/Other.php', "<?php\n\nnamespace {$namespace}\\App;\n\nfinal class Other\n{\n}\n");
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($loader, $namespace . '\App\Order');
    require $directory . '/app/Other.php';

    expect(get_included_files())->toContain($directory . '/app/Order.php')
        ->and($autoloader->uninstrumented(get_included_files()))->toBe([$directory . '/app/Other.php']);
});

it('includes the copy by its own path where the stream wrapper of somebody else answers for files', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader(new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());
    $class = $namespace . '\App\Order';
    // Instrumented beforehand: the wrapper of the test only reads files.
    $autoloader->copyFor($directory . '/app/Order.php');
    ForeignFileWrapper::install();

    try {
        $autoloader->load($loader, $class);
    } finally {
        ForeignFileWrapper::remove();
    }

    expect(new $class()->file())->toBe($directory . '/app/Order.php')
        ->and(new ReflectionClass($class)->getFileName())->toStartWith($directory . '/cache/')
        ->and(array_column($autoloader->run()->files, 0))->toBe([$directory . '/app/Order.php']);
});

it('shares one autoloader in the process, made from the settings the first time, over a private cache', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $shared = new ReflectionProperty(InstrumentingAutoloader::class, 'shared');
    $settings = new Settings($directory . '/vendor/autoload.php', [$directory . '/app'], [$directory . '/app/Providers'], [], $directory . '/cache', $directory . '/runs', true, true, null);
    $otherSettings = new Settings($directory . '/vendor/autoload.php', [$directory . '/lib'], [], [], $directory . '/other-cache', $directory . '/runs', false, false, null);
    mkdir($directory . '/app/Providers');
    file_put_contents($directory . '/app/Providers/AppProvider.php', "<?php\n\nnamespace {$namespace}\\App\\Providers;\n\nfinal class AppProvider\n{\n}\n");
    $loader->addPsr4($namespace . '\\App\\Providers\\', $directory . '/app/Providers');

    try {
        $autoloader = InstrumentingAutoloader::shared($settings);
        $permissions = fileperms($directory . '/cache') & 0o777;
        $again = InstrumentingAutoloader::shared($otherSettings);
        $autoloader->load($loader, $namespace . '\App\Order');
        $autoloader->load($loader, $namespace . '\App\Providers\AppProvider');
        $files = $autoloader->run()->files;
        $copy = (string) file_get_contents($directory . '/cache/' . array_values($files)[0][1] . '.php');
    } finally {
        $shared->setValue(null, null);
    }

    expect($again)->toBe($autoloader)
        ->and($permissions)->toBe(0o700)
        ->and(is_dir($directory . '/other-cache'))->toBeFalse()
        ->and(array_column($files, 0))->toBe([$directory . '/app/Order.php'])
        ->and($copy)->toContain('$__sonda = &\\' . Hits::class . '::$current[');
});

it('refuses to share an autoloader over a cache directory open to other users', function (): void {
    ['directory' => $directory] = fixtureProject();
    mkdir($directory . '/cache', 0o755);
    $settings = new Settings($directory . '/vendor/autoload.php', [$directory . '/app'], [], [], $directory . '/cache', $directory . '/runs', false, false, null);

    expect(fn (): InstrumentingAutoloader => InstrumentingAutoloader::shared($settings))
        ->toThrow(RuntimeException::class, "The directory {$directory}/cache must belong to the current user and be private: chmod 700 on it fixes that.")
        ->and(new ReflectionProperty(InstrumentingAutoloader::class, 'shared')->getValue())->toBeNull();
});
