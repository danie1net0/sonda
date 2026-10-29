<?php

declare(strict_types=1);

use Sonda\IncludeWrapper;

/** Registers the wrapper for the test, giving each path asked for to $copyFor, and puts PHP's back after it. */
function wrapping(Closure $copyFor): void
{
    IncludeWrapper::register($copyFor);
}

afterEach(function (): void {
    IncludeWrapper::unregister();
});

it('includes the instrumented copy of a file the closure maps, and the file itself otherwise', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/original.php', '<?php return "original";');
    file_put_contents($directory . '/copy.php', '<?php return "copy";');
    file_put_contents($directory . '/other.php', '<?php return "other";');
    $asked = [];
    wrapping(static function (string $path) use (&$asked, $directory): ?string {
        $asked[] = $path;

        return $path === $directory . '/original.php' ? $directory . '/copy.php' : null;
    });

    $mapped = include $directory . '/original.php';
    $other = require $directory . '/other.php';
    // Read now: whatever the assertions autoload is included and asked about too.
    $askedByTheIncludes = $asked;

    expect($mapped)->toBe('copy')
        ->and($other)->toBe('other')
        ->and($askedByTheIncludes)->toBe([$directory . '/original.php', $directory . '/other.php']);
});

it('lets the closure read the file being included, to instrument it', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/original.php', '<?php return "original";');
    wrapping(static function (string $path) use ($directory): string {
        // What the instrumenter does: the copy comes from the contents of the file asked for.
        file_put_contents($directory . '/copy.php', str_replace('original', 'copy of ' . strlen(file_get_contents($path)), file_get_contents($path)));

        return $directory . '/copy.php';
    });

    expect(include $directory . '/original.php')->toBe('copy of 24');
});

it('opens a file that is read, not included, as it is, without asking for a copy', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/original.php', 'original');
    $asked = 0;
    wrapping(static function () use (&$asked): string {
        $asked++;

        return '/nowhere';
    });

    expect(file_get_contents($directory . '/original.php'))->toBe('original')
        ->and($asked)->toBe(0);
});

it('reads, writes, seeks, truncates, flushes and stats an open file', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    wrapping(static fn (): ?string => null);

    $handle = fopen($path, 'w+');
    $written = fwrite($handle, 'hello world');
    $flushed = fflush($handle);
    $truncated = ftruncate($handle, 5);
    rewind($handle);
    $read = fread($handle, 100);
    $atEnd = feof($handle);
    $position = ftell($handle);
    $seeked = fseek($handle, 1);
    $rest = stream_get_contents($handle);
    $size = fstat($handle)['size'];
    fclose($handle);

    expect($written)->toBe(11)
        ->and($flushed)->toBeTrue()
        ->and($truncated)->toBeTrue()
        ->and($read)->toBe('hello')
        ->and($atEnd)->toBeTrue()
        ->and($position)->toBe(5)
        ->and($seeked)->toBe(0)
        ->and($rest)->toBe('ello')
        ->and($size)->toBe(5);
});

it('locks an open file, and answers that it can lock when PHP only asks', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    wrapping(static fn (): ?string => null);

    $written = file_put_contents($path, 'locked', LOCK_EX);
    $handle = fopen($path, 'r');
    $locked = flock($handle, LOCK_SH);
    $unlocked = flock($handle, LOCK_UN);
    fclose($handle);

    expect($written)->toBe(6)
        ->and($locked)->toBeTrue()
        ->and($unlocked)->toBeTrue()
        ->and(file_get_contents($path))->toBe('locked');
});

it('passes the stream options it knows on to the file and declines the others', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);

    $handle = fopen($path, 'r');

    expect(stream_set_blocking($handle, true))->toBeTrue()
        ->and(stream_set_blocking($handle, false))->toBeTrue()
        ->and(stream_set_timeout($handle, 1))->toBeFalse()
        ->and(stream_set_write_buffer($handle, 0))->toBe(-1)
        ->and(stream_set_read_buffer($handle, 0))->toBe(-1);

    fclose($handle);
});

it('hands the underlying stream to stream_select', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);

    $handle = fopen($path, 'r');
    $read = [$handle];
    $write = null;
    $except = null;

    expect(stream_select($read, $write, $except, 0))->toBe(1);

    fclose($handle);
});

it('touches, changes the mode, the owner and the group of a file, and refuses values of the wrong kind', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);

    $touched = touch($path);
    $timed = touch($path, 1_000_000_000, 1_000_000_007);
    clearstatcache();
    $modified = filemtime($path);
    $accessed = fileatime($path);
    $chmod = chmod($path, 0o600);
    clearstatcache();
    $mode = fileperms($path) & 0o777;
    $owner = (int) fileowner($path);
    $group = (int) filegroup($path);

    expect($touched)->toBeTrue()
        ->and($timed)->toBeTrue()
        ->and($modified)->toBe(1_000_000_000)
        ->and($accessed)->toBe(1_000_000_007)
        ->and($chmod)->toBeTrue()
        ->and($mode)->toBe(0o600)
        ->and(chown($path, $owner))->toBeTrue()
        ->and(chgrp($path, $group))->toBeTrue()
        ->and(chown($path, (string) posix_getpwuid($owner)['name']))->toBeTrue()
        ->and(chgrp($path, (string) posix_getgrgid($group)['name']))->toBeTrue();
});

it('fails to change the mode of a missing file, with the warning PHP gives', function (): void {
    $path = TemporaryDirectory::create() . '/missing.txt';
    wrapping(static fn (): ?string => null);
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $changed = chmod($path, 0o600);
    } finally {
        restore_error_handler();
    }

    expect($changed)->toBeFalse()
        ->and(implode("\n", $warnings))->toContain('No such file or directory');
});

it('refuses metadata values of the wrong kind', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);
    $wrapper = new IncludeWrapper();

    expect($wrapper->stream_metadata($path, STREAM_META_OWNER, 1.5))->toBeFalse()
        ->and($wrapper->stream_metadata($path, STREAM_META_GROUP, null))->toBeFalse()
        ->and($wrapper->stream_metadata($path, STREAM_META_ACCESS, '0600'))->toBeFalse()

        ->and($wrapper->stream_metadata($path, STREAM_META_TOUCH, null))->toBeTrue();
});

it('stats files and links, and answers false without a warning for a missing one', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/file.txt', 'x');
    symlink($directory . '/file.txt', $directory . '/link.txt');
    symlink($directory . '/gone.txt', $directory . '/dangling.txt');
    wrapping(static fn (): ?string => null);
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $isFile = is_file($directory . '/file.txt');
        $isLink = is_link($directory . '/link.txt');
        $linkTargetIsFile = is_file($directory . '/link.txt');
        $linkSize = lstat($directory . '/link.txt')['size'];
        $targetSize = stat($directory . '/link.txt')['size'];
        $dangling = is_link($directory . '/dangling.txt');
        $missing = file_exists($directory . '/missing.txt');
        $missingLink = is_link($directory . '/missing.txt');
        $wrapper = new IncludeWrapper();
        $quietMissing = $wrapper->url_stat($directory . '/missing.txt', 0);
    } finally {
        restore_error_handler();
    }

    expect($isFile)->toBeTrue()
        ->and($isLink)->toBeTrue()
        ->and($linkTargetIsFile)->toBeTrue()
        ->and($targetSize)->toBe(1)
        ->and($linkSize)->not->toBe(1)
        ->and($dangling)->toBeTrue()
        ->and($missing)->toBeFalse()
        ->and($missingLink)->toBeFalse()
        ->and($quietMissing)->toBeFalse()
        ->and($warnings)->toBe([]);
});

it('creates, renames, lists and removes files and directories', function (): void {
    $directory = TemporaryDirectory::create();
    wrapping(static fn (): ?string => null);

    // Without recursion, the missing parent fails it, with the warning PHP gives.
    set_error_handler(static fn (): bool => true);

    try {
        $flat = mkdir($directory . '/a/b', 0o700);
    } finally {
        restore_error_handler();
    }

    $made = mkdir($directory . '/a/b', 0o700, true);
    file_put_contents($directory . '/a/b/one.txt', '1');
    $renamed = rename($directory . '/a/b/one.txt', $directory . '/a/b/two.txt');
    $entries = scandir($directory . '/a/b');
    $handle = opendir($directory . '/a/b');
    $first = readdir($handle);
    rewinddir($handle);
    $again = readdir($handle);
    closedir($handle);
    $unlinked = unlink($directory . '/a/b/two.txt');
    $removed = rmdir($directory . '/a/b');

    expect($flat)->toBeFalse()
        ->and($made)->toBeTrue()
        ->and($renamed)->toBeTrue()
        ->and($entries)->toBe(['.', '..', 'two.txt'])
        ->and($again)->toBe($first)
        ->and($unlinked)->toBeTrue()
        ->and($removed)->toBeTrue()
        ->and(is_dir($directory . '/a/b'))->toBeFalse();
});

it('fails to open what does not exist with the warnings PHP gives', function (): void {
    wrapping(static fn (): ?string => null);
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $file = fopen('/nowhere/file.txt', 'r');
        $directory = opendir('/nowhere');
    } finally {
        restore_error_handler();
    }

    expect($file)->toBeFalse()
        ->and($directory)->toBeFalse()
        ->and(implode("\n", $warnings))->toContain('fopen(/nowhere/file.txt): Failed to open stream: No such file or directory')
        ->toContain('opendir(/nowhere): Failed to open directory: No such file or directory');
});

it("puts PHP's own wrapper back after each operation and when unregistered", function (): void {
    wrapping(static fn (): ?string => null);
    file_put_contents(TemporaryDirectory::create() . '/file.txt', 'x');

    expect(stream_get_wrappers())->toContain('file');

    IncludeWrapper::unregister();
    IncludeWrapper::unregister();

    expect(new ReflectionProperty(IncludeWrapper::class, 'registered')->getValue())->toBeFalse();
});

it('opens a file through the include path when asked to', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/found.txt', 'found');
    $includePath = (string) get_include_path();
    set_include_path($directory);
    wrapping(static fn (): ?string => null);

    try {
        $handle = fopen('found.txt', 'r', true);
        $contents = fread($handle, 10);
        fclose($handle);
    } finally {
        set_include_path($includePath);
    }

    expect($contents)->toBe('found');
});

it('closes the file it opened, releasing its lock', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);

    $first = fopen($path, 'r');
    flock($first, LOCK_EX);
    fclose($first);
    $second = fopen($path, 'r');
    $locked = flock($second, LOCK_EX | LOCK_NB);
    fclose($second);

    expect($locked)->toBeTrue();
});

it("opens files with its own stream while registered and with PHP's after it is unregistered", function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);
    $wrapped = fopen($path, 'r');
    $wrappedType = stream_get_meta_data($wrapped)['wrapper_type'];
    fclose($wrapped);

    IncludeWrapper::unregister();
    $plain = fopen($path, 'r');
    $plainType = stream_get_meta_data($plain)['wrapper_type'];
    fclose($plain);

    expect($wrappedType)->toBe('user-space')
        ->and($plainType)->toBe('plainfile');
});

it('frees each wrapper PHP drops when it hands an operation to PHP, and when it is registered again', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'x');
    wrapping(static fn (): ?string => null);
    // PHP keeps a "stream factory" resource per registered user wrapper, until the process ends
    // if dropped without being freed: a wrapper that leaks shows up here, operation by operation.
    $before = count(get_resources('stream factory'));

    for ($i = 0; $i < 100; $i++) {
        file_exists($path);
        fclose(fopen($path, 'r'));
        IncludeWrapper::unregister();
        wrapping(static fn (): ?string => null);
    }

    expect(count(get_resources('stream factory')))->toBe($before);
});
