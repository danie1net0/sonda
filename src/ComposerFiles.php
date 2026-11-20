<?php

declare(strict_types=1);

namespace Sonda;

use ArrayAccess;
use Closure;
use Composer\Autoload\ClassLoader;
use ReflectionFunction;

use function dirname;

/**
 * Stands in for the list where Composer keeps which files of its `files` autoload (helpers,
 * mostly) it has required, while Composer requires them. Composer asks the list before each file:
 * a file of the scope is loaded right then from its instrumented copy, in Composer's own order,
 * and answered as already required. The first question also comes right after Composer registers
 * its class loader, which is when the instrumenting autoloader goes in front of it. With both
 * done, the plain list goes back to its place.
 *
 * @implements ArrayAccess<int|string, bool>
 */
final class ComposerFiles implements ArrayAccess
{
    private const string LIST = '__composer_autoload_files';

    /**
     * @param string                    $vendorDirectory the real path of the project's vendor directory, the key of its class loader
     * @param array<int|string, bool>   $loaded          what Composer takes as already required
     * @param array<int|string, string> $pending         identifier => real path of the files of the scope still to load
     */
    private function __construct(
        private readonly Settings $settings,
        private readonly string $vendorDirectory,
        private array $loaded,
        private array $pending,
    ) {
    }

    /**
     * Takes the place of Composer's list, before the project's autoloader is required. When it
     * already was, or is not there to be, or the list is already taken (by the prepend file, before
     * the bootstrap), there is nothing left to do.
     */
    public static function intercept(Settings $settings): void
    {
        $autoloadPath = realpath($settings->autoloadPath);
        $loaded = $GLOBALS[self::LIST] ?? [];

        if ($autoloadPath === false || $loaded instanceof self || in_array($autoloadPath, get_included_files(), true)) {
            return;
        }

        /** @var array<int|string, bool> $loaded */
        $scope = $settings->scope();
        $pending = [];

        foreach (self::listed($settings->autoloadPath) as $identifier => $path) {
            // A file that Composer has required already stays as it is: again, it would declare its functions twice.
            if ($scope->includes($path) && ! isset($loaded[$identifier])) {
                $pending[$identifier] = $path;
            }
        }

        $GLOBALS[self::LIST] = new self($settings, dirname($autoloadPath), $loaded, $pending);
    }

    /**
     * Puts the autoloader of sonda's own classes (autoload.php) back in front of Composer's, which
     * registers itself ahead of every other: the project may have another version of sonda
     * installed, and its classes must not answer for the ones of this copy.
     */
    public static function keepOwnClassesFirst(): void
    {
        $ownAutoload = realpath(__DIR__ . '/../autoload.php');

        foreach (spl_autoload_functions() as $autoloader) {
            if ($autoloader instanceof Closure && new ReflectionFunction($autoloader)->getFileName() === $ownAutoload) {
                spl_autoload_unregister($autoloader);
                spl_autoload_register($autoloader, prepend: true);
            }
        }
    }

    /**
     * The files of Composer's `files` autoload that exist, by their identifier.
     *
     * @param string $autoloadPath the project's vendor/autoload.php
     *
     * @return array<int|string, string> identifier => real path
     */
    public static function listed(string $autoloadPath): array
    {
        $listing = dirname($autoloadPath) . '/composer/autoload_files.php';

        if (! is_file($listing)) {
            return [];
        }

        $files = require $listing;
        $listed = [];

        foreach (is_array($files) ? $files : [] as $identifier => $file) {
            $path = is_string($file) ? realpath($file) : false;

            if ($path !== false) {
                $listed[$identifier] = $path;
            }
        }

        return $listed;
    }

    /** Composer asks whether it has required the file: one of the scope is loaded here, instrumented. */
    public function offsetExists(mixed $offset): bool
    {
        $registered = $this->registerAutoloader();

        if (! isset($this->pending[$offset])) {
            $this->leaveWhenDone($registered);

            return isset($this->loaded[$offset]);
        }

        $path = $this->pending[$offset];
        unset($this->pending[$offset]);
        $this->loaded[$offset] = true;
        InstrumentingAutoloader::shared($this->settings)->loadFile($path);
        $this->leaveWhenDone($registered);

        return true;
    }

    public function offsetGet(mixed $offset): bool
    {
        return $this->loaded[$offset] ?? false;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->loaded[] = $value;

            return;
        }

        $this->loaded[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->loaded[$offset]);
    }

    /** Puts the instrumenting autoloader in front of Composer's, once the project's class loader is registered. */
    private function registerAutoloader(): bool
    {
        $composer = ClassLoader::getRegisteredLoaders()[$this->vendorDirectory] ?? null;

        if (! $composer instanceof ClassLoader) {
            return false;
        }

        // Before any other class of sonda loads: from here on they would come through Composer.
        self::keepOwnClassesFirst();
        InstrumentingAutoloader::shared($this->settings)->register($composer);

        return true;
    }

    /** With the autoloader registered and no file of the scope left, the plain list takes its place back. */
    private function leaveWhenDone(bool $registered): void
    {
        if ($registered && $this->pending === []) {
            $GLOBALS[self::LIST] = $this->loaded;
        }
    }
}
