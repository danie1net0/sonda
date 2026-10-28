<?php

declare(strict_types=1);

namespace Sonda;

use Closure;

/**
 * The `file://` stream wrapper while sonda runs: a file of the scope that code includes or
 * requires by its path, outside the autoloader (a Laravel migration, a routes file, a test that
 * requires a file), opens its instrumented copy instead. Every other operation, and every other
 * file, goes to PHP's own wrapper untouched.
 *
 * PHP has one wrapper per protocol: each operation puts PHP's back for its duration. Restoring
 * PHP's over this one would drop this one without freeing it, and PHP would hold the memory until
 * the process ends; unregistering it first frees it, and nothing is kept.
 */
final class IncludeWrapper
{
    private const string PROTOCOL = 'file';

    /** The flag PHP sets on stream_open for include and require; it has no constant in userland. */
    private const int OPEN_FOR_INCLUDE = 128;

    /** @var resource|null set by PHP to the context of the operation */
    public mixed $context = null;

    /** @var Closure(string): ?string the instrumented copy of a path, or null to open the path itself */
    private static Closure $copyFor;

    private static bool $registered = false;

    /** @var resource the open file, from a successful stream_open on */
    private mixed $handle;

    /** @var resource the open directory, from a successful dir_opendir on */
    private mixed $directory;

    /** @param Closure(string): ?string $copyFor the instrumented copy to include in place of a path, or null for none */
    public static function register(Closure $copyFor): void
    {
        self::$copyFor = $copyFor;
        self::$registered = true;
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    /** Puts PHP's own wrapper back; a second call finds nothing to undo. */
    public static function unregister(): void
    {
        if (! self::$registered) {
            return;
        }

        self::$registered = false;
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_restore(self::PROTOCOL);
    }

    public function stream_open(string $path, string $mode, int $options): bool
    {
        if (($options & self::OPEN_FOR_INCLUDE) !== 0) {
            $path = (self::$copyFor)($path) ?? $path;
        }

        // PHP resolves the include path before it reaches the wrapper: the path here is the file.
        $handle = $this->native(fn (): mixed => fopen($path, $mode, false, $this->context));

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /** @param int<1, max> $count PHP reads in chunks, never asking for nothing */
    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    /** @param int<0, max> $size ftruncate() refuses a negative size before it reaches the wrapper */
    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_lock(int $operation): bool
    {
        // PHP asks with 0 whether the stream can be locked at all (file_put_contents() with LOCK_EX).
        if ($operation === 0) {
            return true;
        }

        /** @var int<1, 7> $operation LOCK_SH, LOCK_EX or LOCK_UN, with LOCK_NB, as flock() received them */
        return flock($this->handle, $operation);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * What a plain file answers: it takes the blocking mode, which changes nothing for it, and
     * has no timeouts nor buffers to set.
     */
    public function stream_set_option(int $option): bool
    {
        return $option === STREAM_OPTION_BLOCKING;
    }

    /** @return resource */
    public function stream_cast(): mixed
    {
        return $this->handle;
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return $this->native(static fn (): bool => self::metadata($path, $option, $value));
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $link = ($flags & STREAM_URL_STAT_LINK) !== 0;

        // PHP's own wrapper never warns here: a missing file is a false the calling function
        // (filesize(), is_file()) turns into its own warning, or into an answer. Checking first
        // keeps the warning from being raised at all, which an error handler would see even under @.
        return $this->native(static function () use ($path, $link): array|false {
            // A link asks after the link itself, which exists even when what it points to does not.
            if ($link) {
                return is_link($path) || file_exists($path) ? lstat($path) : false;
            }

            return file_exists($path) ? stat($path) : false;
        });
    }

    public function unlink(string $path): bool
    {
        return $this->native(fn (): bool => unlink($path, $this->context));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->native(fn (): bool => rename($from, $to, $this->context));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $recursive = ($options & STREAM_MKDIR_RECURSIVE) !== 0;

        return $this->native(fn (): bool => mkdir($path, $mode, $recursive, $this->context));
    }

    public function rmdir(string $path): bool
    {
        return $this->native(fn (): bool => rmdir($path, $this->context));
    }

    public function dir_opendir(string $path): bool
    {
        $directory = $this->native(fn (): mixed => opendir($path, $this->context));

        if ($directory === false) {
            return false;
        }

        $this->directory = $directory;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->directory);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->directory);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->directory);

        return true;
    }

    /**
     * touch(), chmod(), chown() and chgrp(): PHP hands touch() an array of the two times, chmod()
     * the mode, chown() and chgrp() a name or an id. These are the six operations it sends.
     */
    private static function metadata(string $path, int $option, mixed $value): bool
    {
        if ($option === STREAM_META_TOUCH) {
            return self::touch($path, $value);
        }

        if ($option === STREAM_META_ACCESS) {
            return is_int($value) && chmod($path, $value);
        }

        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        return $option === STREAM_META_OWNER || $option === STREAM_META_OWNER_NAME ? chown($path, $value) : chgrp($path, $value);
    }

    private static function touch(string $path, mixed $times): bool
    {
        // touch() without times hands an empty array; with them, the modification and the access time.
        if (! is_array($times) || $times === []) {
            return touch($path);
        }

        /** @var array{0: int, 1: int} $times */
        return touch($path, $times[0], $times[1]);
    }

    /**
     * Runs a filesystem operation with PHP's own wrapper, then puts this one back.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function native(Closure $operation): mixed
    {
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_restore(self::PROTOCOL);

        try {
            return $operation();
        } finally {
            stream_wrapper_unregister(self::PROTOCOL);
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }
}
