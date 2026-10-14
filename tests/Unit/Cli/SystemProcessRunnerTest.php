<?php

declare(strict_types=1);

use Sonda\Cli\SystemProcessRunner;

/** @return resource */
function stream(string $path, string $mode)
{
    $stream = fopen($path, $mode);

    if ($stream === false) {
        throw new RuntimeException("Não foi possível abrir {$path}.");
    }

    return $stream;
}

it('runs a command in a directory with the streams and environment given, and returns its exit code', function (): void {
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/input.txt', "typed\n");
    $runner = new SystemProcessRunner(stream($directory . '/input.txt', 'r'), stream($directory . '/output.txt', 'w'), stream($directory . '/error.txt', 'w'));

    $status = $runner->run(
        [PHP_BINARY, '-r', 'echo "out ", fgets(STDIN); fwrite(STDERR, "err"); exit(getenv("SONDA_PROBE") === "ok" && getenv("PATH") !== false && getcwd() === $argv[1] ? 3 : 9);', $directory],
        ['SONDA_PROBE' => 'ok'],
        $directory,
    );

    expect($status)->toBe(3)
        ->and(file_get_contents($directory . '/output.txt'))->toBe("out typed\n")
        ->and(file_get_contents($directory . '/error.txt'))->toBe('err');
});
