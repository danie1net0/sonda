<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Sonda\{ComposerFiles, InstrumentingAutoloader, Settings};

/**
 * A project with a files listing: one file in app, one outside, one gone. The functions carry a
 * suffix unique to the test, because a function cannot be declared twice in the test process.
 *
 * @return array{root: string, autoload: string, suffix: string, settings: Settings}
 */
function projectWithFiles(?string $target = null): array
{
    $root = TemporaryDirectory::create();
    $suffix = bin2hex(random_bytes(6));
    mkdir($root . '/app');
    mkdir($root . '/lib');
    mkdir($root . '/vendor/composer', 0777, true);
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction app_helper_{$suffix}(): string\n{\n    return __FILE__;\n}\n");
    file_put_contents($root . '/lib/helpers.php', "<?php\n\nfunction lib_helper_{$suffix}(): int\n{\n    return 2;\n}\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', <<<'LISTING'
    <?php

    $vendorDir = dirname(__DIR__);
    $baseDir = dirname($vendorDir);

    return array(
        'app1' => $baseDir . '/app/helpers.php',
        'lib1' => $baseDir . '/lib/helpers.php',
        'gone' => $baseDir . '/app/gone.php',
        7 => 42,
    );
    LISTING);

    $result = [];
    $result['root'] = $root;
    $result['autoload'] = $root . '/vendor/autoload.php';
    $result['suffix'] = $suffix;
    $result['settings'] = new Settings($root . '/vendor/autoload.php', [$target ?? $root . '/app'], [], [], $root . '/cache', $root . '/runs', false, false, null);

    return $result;
}

/** Registers the class loader of the project's vendor directory, as Composer does before it requires its files. */
function registerClassLoader(string $root): ClassLoader
{
    $loader = new ClassLoader($root . '/vendor');
    $loader->register();

    return $loader;
}

/** What Composer's autoloader does with each file of its `files`. */
function requireAsComposer(string $root): void
{
    foreach (ComposerFiles::listed($root . '/vendor/autoload.php') as $identifier => $file) {
        if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
            $GLOBALS['__composer_autoload_files'][$identifier] = true;

            require $file;
        }
    }
}

/**
 * Composer marks its own files in the test process: each test starts from an empty list, and the
 * list and the autoloaders go back to what they were.
 */
beforeEach(function (): void {
    $this->loadedBefore = $GLOBALS['__composer_autoload_files'] ?? null;
    $this->autoloadersBefore = spl_autoload_functions();
    $this->classLoaders = [];
    unset($GLOBALS['__composer_autoload_files']);
});

afterEach(function (): void {
    $GLOBALS['__composer_autoload_files'] = $this->loadedBefore;

    if ($this->loadedBefore === null) {
        unset($GLOBALS['__composer_autoload_files']);
    }

    foreach ($this->classLoaders as $classLoader) {
        $classLoader->unregister();
    }

    foreach (spl_autoload_functions() as $autoloader) {
        if (! in_array($autoloader, $this->autoloadersBefore, true)) {
            spl_autoload_unregister($autoloader);
        }
    }

    new ReflectionProperty(InstrumentingAutoloader::class, 'shared')->setValue(null, null);
});

it('loads a file of the scope from its instrumented copy when Composer reaches it, and answers it as required', function (): void {
    ['root' => $root, 'suffix' => $suffix, 'settings' => $settings] = projectWithFiles();

    ComposerFiles::intercept($settings);
    $taken = $GLOBALS['__composer_autoload_files'];
    $this->classLoaders[] = registerClassLoader($root);
    requireAsComposer($root);
    $files = InstrumentingAutoloader::shared($settings)->run()->files;
    $first = spl_autoload_functions()[0];

    expect($taken)->toBeInstanceOf(ComposerFiles::class)
        ->and(("app_helper_{$suffix}")())->toBe($root . '/app/helpers.php')
        ->and(new ReflectionFunction("app_helper_{$suffix}")->getFileName())->toStartWith($root . '/cache/')
        ->and(("lib_helper_{$suffix}")())->toBe(2)
        ->and(new ReflectionFunction("lib_helper_{$suffix}")->getFileName())->toBe($root . '/lib/helpers.php')
        ->and($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true, 'lib1' => true])
        ->and(array_column($files, 0))->toBe([$root . '/app/helpers.php'])
        ->and($first)->toBeInstanceOf(Closure::class)
        ->and(new ReflectionFunction($first)->getClosureThis())->toBe(InstrumentingAutoloader::shared($settings));
});

it('keeps what Composer had already required', function (): void {
    ['root' => $root, 'settings' => $settings] = projectWithFiles();
    $GLOBALS['__composer_autoload_files'] = ['other' => true];

    ComposerFiles::intercept($settings);
    $this->classLoaders[] = registerClassLoader($root);
    $otherIsRequired = ! empty($GLOBALS['__composer_autoload_files']['other']);
    requireAsComposer($root);

    expect($otherIsRequired)->toBeTrue()
        ->and($GLOBALS['__composer_autoload_files'])->toBe(['other' => true, 'app1' => true, 'lib1' => true]);
});

it('leaves alone a file of the scope that Composer had already required', function (): void {
    ['root' => $root, 'suffix' => $suffix, 'settings' => $settings] = projectWithFiles();
    $GLOBALS['__composer_autoload_files'] = ['app1' => true];

    ComposerFiles::intercept($settings);
    $this->classLoaders[] = registerClassLoader($root);
    requireAsComposer($root);

    expect($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true, 'lib1' => true])
        ->and(function_exists("app_helper_{$suffix}"))->toBeFalse()
        ->and(InstrumentingAutoloader::shared($settings)->run()->files)->toBe([]);
});

it('stays until the class loader of the project is registered, answering as the list would', function (): void {
    ['root' => $root, 'suffix' => $suffix, 'settings' => $settings] = projectWithFiles();
    ComposerFiles::intercept($settings);
    $list = $GLOBALS['__composer_autoload_files'];

    $unknown = isset($list['unknown']);
    $unknownValue = $list->offsetGet('unknown');
    $list['marked'] = true;
    $list[] = true;
    $marked = [isset($list['marked']), $list['marked'], isset($list[0]), $list[0]];
    unset($list['marked'], $list[0]);
    $unmarked = isset($list['marked']) || isset($list[0]);
    $scopeFile = isset($list['app1']);
    $stillTaken = $GLOBALS['__composer_autoload_files'] === $list;
    $this->classLoaders[] = registerClassLoader($root);
    $again = [isset($list['app1']), $list['app1']];

    expect($unknown)->toBeFalse()
        ->and($unknownValue)->toBeFalse()
        ->and($marked)->toBe([true, true, true, true])
        ->and($unmarked)->toBeFalse()
        ->and($scopeFile)->toBeTrue()
        ->and(function_exists("app_helper_{$suffix}"))->toBeTrue()
        ->and($stillTaken)->toBeTrue()
        ->and($again)->toBe([true, true])
        ->and($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true]);
});

it('leaves right after the last file of the scope, even when it is the last that Composer requires', function (): void {
    ['root' => $root, 'suffix' => $suffix, 'settings' => $settings] = projectWithFiles();
    $settings = new Settings($settings->autoloadPath, [$root . '/lib'], [], [], $settings->cacheDirectory, $settings->outputDirectory, false, false, null);

    ComposerFiles::intercept($settings);
    $this->classLoaders[] = registerClassLoader($root);
    requireAsComposer($root);

    expect($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true, 'lib1' => true])
        ->and(new ReflectionFunction("lib_helper_{$suffix}")->getFileName())->toStartWith($root . '/cache/')
        ->and(new ReflectionFunction("app_helper_{$suffix}")->getFileName())->toBe($root . '/app/helpers.php');
});

it('has nothing to intercept without an autoloader of the project', function (): void {
    ['autoload' => $autoload, 'settings' => $settings] = projectWithFiles();
    unlink($autoload);

    ComposerFiles::intercept($settings);

    expect(isset($GLOBALS['__composer_autoload_files']))->toBeFalse();
});

it('leaves at the first question when no file of the scope is listed', function (): void {
    ['root' => $root, 'suffix' => $suffix, 'settings' => $settings] = projectWithFiles(TemporaryDirectory::create());

    ComposerFiles::intercept($settings);
    $this->classLoaders[] = registerClassLoader($root);
    requireAsComposer($root);

    expect($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true, 'lib1' => true])
        ->and(new ReflectionFunction("app_helper_{$suffix}")->getFileName())->toBe($root . '/app/helpers.php')
        ->and(InstrumentingAutoloader::shared($settings)->run()->files)->toBe([]);
});

it("has nothing to intercept once the project's autoloader was required", function (): void {
    ['autoload' => $autoload, 'settings' => $settings] = projectWithFiles();
    require $autoload;
    $GLOBALS['__composer_autoload_files'] = ['other' => true];

    ComposerFiles::intercept($settings);

    expect($GLOBALS['__composer_autoload_files'])->toBe(['other' => true]);
});

it('takes the list only once', function (): void {
    ['settings' => $settings] = projectWithFiles();

    ComposerFiles::intercept($settings);
    $taken = $GLOBALS['__composer_autoload_files'];
    ComposerFiles::intercept($settings);

    expect($GLOBALS['__composer_autoload_files'])->toBe($taken);
});

it('lists the files Composer would require that exist, by identifier, without touching its list', function (): void {
    ['root' => $root, 'autoload' => $autoload] = projectWithFiles();

    expect(ComposerFiles::listed($autoload))->toBe(['app1' => $root . '/app/helpers.php', 'lib1' => $root . '/lib/helpers.php'])
        ->and(isset($GLOBALS['__composer_autoload_files']))->toBeFalse();
});

it('lists nothing without a files listing, or from one that is not a list of files', function (): void {
    ['root' => $root, 'autoload' => $autoload] = projectWithFiles();
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn 'broken';\n");

    expect(ComposerFiles::listed($autoload))->toBe([])
        ->and(ComposerFiles::listed(TemporaryDirectory::create() . '/vendor/autoload.php'))->toBe([]);
});

it("puts the autoloader of sonda's own classes back in front of the others", function (): void {
    $before = spl_autoload_functions();
    require dirname(__DIR__, 2) . '/autoload.php';
    $other = static function (string $class): void {
    };
    spl_autoload_register($other, prepend: true);

    ComposerFiles::keepOwnClassesFirst();
    $order = array_values(array_filter(spl_autoload_functions(), static fn (mixed $function): bool => ! in_array($function, $before, true)));

    expect($order)->toHaveCount(2)
        ->and(new ReflectionFunction($order[0])->getFileName())->toBe(dirname(__DIR__, 2) . '/autoload.php')
        ->and($order[1])->toBe($other);
});

it("puts the autoloader of sonda's own classes in front of Composer's when the project registers it", function (): void {
    ['root' => $root, 'settings' => $settings] = projectWithFiles();
    require dirname(__DIR__, 2) . '/autoload.php';

    ComposerFiles::intercept($settings);
    $this->classLoaders[] = registerClassLoader($root);
    $this->classLoaders[0]->unregister();
    $this->classLoaders[0]->register(true);
    requireAsComposer($root);
    $order = spl_autoload_functions();

    expect(new ReflectionFunction($order[0])->getClosureThis())->toBe(InstrumentingAutoloader::shared($settings))
        ->and(new ReflectionFunction($order[1])->getFileName())->toBe(dirname(__DIR__, 2) . '/autoload.php')
        ->and($order[2])->toBe([$this->classLoaders[0], 'loadClass']);
});
