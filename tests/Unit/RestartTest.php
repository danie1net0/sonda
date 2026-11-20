<?php

declare(strict_types=1);

use Sonda\Restart;

/** @return array{0: int, 1: string} the exit code and what the command printed */
function commandStatusAndOutput(string ...$command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    $result = [];
    $result[] = proc_close($process);
    $result[] = $output;

    return $result;
}

it('starts the process again with the prepend file, keeping its arguments', function (): void {
    $script = TemporaryDirectory::create() . '/runner.php';
    file_put_contents($script, '<?php');

    expect(Restart::command('/usr/bin/php', '/sonda/prepend.php', [$script, '--filter', 'Order'], ''))
        ->toBe(['/usr/bin/php', '-d', 'auto_prepend_file=/sonda/prepend.php', $script, '--filter', 'Order'])
        ->and(Restart::command('/usr/bin/php', '/sonda/prepend.php', [$script], '/user/prepend.php'))
        ->toBe(['/usr/bin/php', '-d', 'auto_prepend_file=/sonda/prepend.php', $script]);
});

it('does not start again a process that already runs with the prepend file', function (): void {
    $script = TemporaryDirectory::create() . '/runner.php';
    file_put_contents($script, '<?php');

    expect(Restart::command('/usr/bin/php', '/sonda/prepend.php', [$script], '/sonda/prepend.php'))->toBeNull();
});

it('does not start again a process whose code is not in a file', function (): void {
    expect(Restart::command('/usr/bin/php', '/sonda/prepend.php', ['Standard input code'], ''))->toBeNull()
        ->and(Restart::command('/usr/bin/php', '/sonda/prepend.php', [TemporaryDirectory::create()], ''))->toBeNull()
        ->and(Restart::command('/usr/bin/php', '/sonda/prepend.php', [], ''))->toBeNull();
});

it('writes a PHP that always runs with the prepend file, whatever the characters of its paths', function (): void {
    $directory = TemporaryDirectory::create() . "/it's a cache";
    mkdir($directory);
    $prepend = $directory . '/prepend.php';
    $script = $directory . '/script.php';
    file_put_contents($prepend, '<?php echo "prepended:";');
    file_put_contents($script, '<?php echo ini_get("auto_prepend_file"), ":", $argv[1], ":", PHP_BINARY; exit(7);');

    $launcher = Restart::launcher($directory, PHP_BINARY, $prepend);
    [$status, $output] = commandStatusAndOutput($launcher, $script, 'two words');

    expect($launcher)->toBe($directory . '/php-with-prepend')
        ->and(fileperms($launcher) & 0o777)->toBe(0o700)
        ->and(scandir($directory))->toBe(['.', '..', 'php-with-prepend', 'prepend.php', 'script.php'])
        ->and(file_get_contents($launcher))->toBe("#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('auto_prepend_file=' . $prepend) . " \"\$@\"\n")
        ->and($output)->toBe('prepended:' . $prepend . ':two words:' . PHP_BINARY)
        ->and($status)->toBe(7);
});

it('runs a command on the streams of the process and gives its status', function (): void {
    $witness = TemporaryDirectory::create() . '/ran';

    $status = Restart::spawn([PHP_BINARY, '-r', 'touch($argv[1]); exit(3);', '--', $witness]);

    expect($status)->toBe(3)
        ->and(is_file($witness))->toBeTrue();
});

it('gives a failure for a command that cannot start', function (): void {
    set_error_handler(static fn (): bool => true);

    try {
        $status = Restart::spawn([TemporaryDirectory::create() . '/missing-php']);
    } finally {
        restore_error_handler();
    }

    expect($status)->toBe(1);
});

it('replaces the process, and runs the command as a child when it could not', function (): void {
    $calls = [];
    $restart = new Restart(
        static function (string $binary, array $arguments) use (&$calls): bool {
            $calls[] = ['replace', $binary, $arguments];

            return false;
        },
        static function (array $command) use (&$calls): int {
            $calls[] = ['spawn', $command];

            return 5;
        },
        static function (int $status) use (&$calls): void {
            $calls[] = ['terminate', $status];
        },
    );

    $restart->run(['/usr/bin/php', '-d', 'x=1', 'runner.php']);

    expect($calls)->toBe([
        ['replace', '/usr/bin/php', ['-d', 'x=1', 'runner.php']],
        ['spawn', ['/usr/bin/php', '-d', 'x=1', 'runner.php']],
        ['terminate', 5],
    ]);
});

it('runs the command as a child where PHP cannot replace the process', function (): void {
    $calls = [];
    $restart = new Restart(
        null,
        static function (array $command) use (&$calls): int {
            $calls[] = ['spawn', $command];

            return 0;
        },
        static function (int $status) use (&$calls): void {
            $calls[] = ['terminate', $status];
        },
    );

    $restart->run(['/usr/bin/php', 'runner.php']);

    expect($calls)->toBe([['spawn', ['/usr/bin/php', 'runner.php']], ['terminate', 0]]);
});

it('uses the functions of PHP, replacing the process only where it can', function (): void {
    $functions = static function (Restart $restart): array {
        $names = [];

        foreach (['replace', 'spawn', 'terminate'] as $property) {
            $function = new ReflectionProperty($restart, $property)->getValue($restart);
            $names[] = $function instanceof Closure ? new ReflectionFunction($function)->getName() : null;
        }

        return $names;
    };

    expect($functions(Restart::native(true)))->toBe(['pcntl_exec', 'spawn', 'exit'])
        ->and($functions(Restart::native(false)))->toBe([null, 'spawn', 'exit']);
});
