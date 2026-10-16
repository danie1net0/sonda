<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Sonda\{Hits, Instrumenter, InstrumentingAutoloader, Run, Scope};

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

    return ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader];
}

it('loads a class of the target directory from its instrumented copy', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());
    $class = $namespace . '\App\Order';

    $autoloader->load($class);
    $order = new $class();
    $total = $order->total(3);
    $filePath = $order->file();

    $run = $autoloader->run();
    $fileId = array_key_first($run->files);

    expect($fileId)->toBe(substr(sha1($directory . '/app/Order.php'), 0, 12))
        ->and($total)->toBe(6)
        ->and($filePath)->toBe($directory . '/app/Order.php')
        ->and($run->files)->toBe([$fileId => [$directory . '/app/Order.php', $run->files[$fileId][1]]])
        ->and(is_file($directory . '/cache/' . $run->files[$fileId][1] . '.php'))->toBeTrue()
        ->and(Hits::$current[$fileId])->toBe([0 => 1, 1 => 1])
        ->and($run->hits)->toBe(Hits::collected())
        ->and($run->cacheDirectory)->toBe($directory . '/cache');
});

it('reuses the instrumented copy already in the cache', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $instrumenter = new Instrumenter();
    $path = $directory . '/app/Order.php';
    $key = sha1($path . filemtime($path) . $instrumenter->fingerprint());
    mkdir($directory . '/cache');
    file_put_contents($directory . "/cache/{$key}.php", "<?php\n\nnamespace {$namespace}\\App;\n\nfinal class Order\n{\n    public const CACHED = true;\n}\n");

    new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', $instrumenter)->load($namespace . '\App\Order');

    expect(constant($namespace . '\App\Order::CACHED'))->toBeTrue();
});

it('leaves classes outside the target directory to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($namespace . '\Lib\Helper');
    $autoloader->load($namespace . '\Missing\Thing');

    expect(class_exists($namespace . '\Lib\Helper', false))->toBeFalse()
        ->and($autoloader->run()->files)->toBe([])
        ->and(is_dir($directory . '/cache'))->toBeFalse();
});

it('registers itself before the other autoloaders', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->register();

    $first = spl_autoload_functions()[0];
    spl_autoload_unregister($first);

    expect($first)->toBeInstanceOf(Closure::class)
        ->and(new ReflectionFunction($first)->getClosureThis())->toBe($autoloader)
        ->and(fileperms($directory . '/cache') & 0o777)->toBe(0o700);
});

it('refuses to register over a cache directory open to other users', function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/cache', 0o755);
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    expect(fn () => $autoloader->register())
        ->toThrow(RuntimeException::class, "O diretório {$directory}/cache precisa pertencer ao usuário atual e ser privado: chmod 700 nele resolve.");
});

it('reports a run with the probes collected so far', function (): void {
    Hits::$perTest = true;
    Hits::$accumulated = ['abc' => [1 => 1]];
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();

    $run = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter())->run();

    expect($run)->toEqual(new Run($directory . '/cache', [], ['abc' => [1 => 1]]));
});

it('does not take a directory that only shares a prefix with the target as part of it', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/application');
    file_put_contents($directory . '/application/Invoice.php', "<?php\n\nnamespace {$namespace}\\Application;\n\nfinal class Invoice\n{\n}\n");
    $loader->addPsr4($namespace . '\\Application\\', $directory . '/application');
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($namespace . '\Application\Invoice');

    expect($autoloader->run()->files)->toBe([]);
});

it('leaves a class whose mapped file was deleted to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    $loader->addClassMap([$namespace . '\App\Deleted' => $directory . '/app/Deleted.php']);
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->load($namespace . '\App\Deleted');

    expect($autoloader->run()->files)->toBe([]);
});

it('leaves the excluded directories and the files out of a narrowed scope to Composer', function (): void {
    ['directory' => $directory, 'namespace' => $namespace, 'loader' => $loader] = fixtureProject();
    mkdir($directory . '/app/Providers');
    file_put_contents($directory . '/app/Providers/AppProvider.php', "<?php\n\nnamespace {$namespace}\\App\\Providers;\n\nfinal class AppProvider\n{\n}\n");
    $scope = new Scope([$directory . '/app'], [$directory . '/app/Providers'], [$directory . '/app/Providers/AppProvider.php']);
    $autoloader = new InstrumentingAutoloader($loader, $scope, $directory . '/cache', new Instrumenter());

    $autoloader->load($namespace . '\App\Providers\AppProvider');
    $autoloader->load($namespace . '\App\Order');

    expect($autoloader->run()->files)->toBe([]);
});

it("loads a file of the scope by its path from its instrumented copy, as one of Composer's files", function (): void {
    ['directory' => $directory, 'loader' => $loader] = fixtureProject();
    $function = 'helper' . bin2hex(random_bytes(6));
    file_put_contents($directory . '/app/helpers.php', "<?php\n\nfunction {$function}(int \$x): int\n{\n    return \$x + 1;\n}\n");
    $autoloader = new InstrumentingAutoloader($loader, new Scope([$directory . '/app']), $directory . '/cache', new Instrumenter());

    $autoloader->loadFile($directory . '/app/helpers.php');

    $result = $function(1);

    $run = $autoloader->run();
    $fileId = array_key_first($run->files);

    expect($result)->toBe(2)
        ->and($run->files)->toBe([$fileId => [$directory . '/app/helpers.php', $run->files[$fileId][1]]])
        ->and($run->hits[$fileId])->not->toBe([]);
});
