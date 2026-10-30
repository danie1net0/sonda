<?php

declare(strict_types=1);

use Sonda\Cli\{Application, CompareCommand, ProcessRunner, TestCommand};

final class RecordingRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    /** @var array<string, string> */
    public array $environment = [];

    public function run(array $command, array $environment, string $directory): int
    {
        $this->command = $command;
        $this->environment = $environment;

        return 0;
    }
}

/** @return array{0: Application, 1: resource, 2: resource, 3: RecordingRunner} */
function application(): array
{
    $processes = new RecordingRunner();
    $output = fopen('php://memory', 'w+');
    $error = fopen('php://memory', 'w+');

    if ($output === false || $error === false) {
        throw new RuntimeException('Sem memória para a saída.');
    }

    return [new Application(new TestCommand($processes, '/sonda', TemporaryDirectory::create() . '/sonda'), new CompareCommand($processes, '/sonda'), '1.2.3'), $output, $error, $processes];
}

/** @param resource $stream */
function contents($stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('prints the usage for help', function (string $argument): void {
    [$application, $output, $error] = application();

    expect($application->run([$argument], '/project', $output, $error))->toBe(0)
        ->and(contents($output))->toBe(Application::USAGE . "\n")
        ->and(contents($error))->toBe('');
})->with(['help', '--help', '-h']);

it('prints its version', function (string $argument): void {
    [$application, $output, $error] = application();

    expect($application->run([$argument], '/project', $output, $error))->toBe(0)
        ->and(contents($output))->toBe("sonda 1.2.3\n")
        ->and(contents($error))->toBe('');
})->with(['--version', '-V']);

it('prints the usage without a command', function (): void {
    [$application, $output, $error] = application();

    expect($application->run([], '/project', $output, $error))->toBe(0)
        ->and(contents($output))->toContain('uso: sonda test');
});

it('rejects an unknown command', function (): void {
    [$application, $output, $error] = application();

    expect($application->run(['measure'], '/project', $output, $error))->toBe(1)
        ->and(contents($error))->toBe("Comando desconhecido: measure\n\n" . Application::USAGE . "\n")
        ->and(contents($output))->toBe('');
});

it('reports a failure of the test command on the error stream', function (): void {
    [$application, $output, $error] = application();
    $project = TemporaryDirectory::create();

    expect($application->run(['test'], $project, $output, $error))->toBe(1)
        ->and(contents($error))->toBe("Nenhum phpunit.xml ou phpunit.xml.dist em {$project}.\n");
});

it('reports a bad option on the error stream', function (): void {
    [$application, $output, $error] = application();

    expect($application->run(['test', '--changed='], '/project', $output, $error))->toBe(1)
        ->and(contents($error))->toBe("A opção --changed precisa de um valor.\n");
});

it('hands the arguments after the command to the test command', function (): void {
    [$application, $output, $error, $runner] = application();
    $project = TemporaryDirectory::create();
    mkdir($project . '/vendor/bin', 0777, true);
    mkdir($project . '/app');
    file_put_contents($project . '/vendor/bin/phpunit', '');
    file_put_contents($project . '/vendor/autoload.php', '<?php');
    file_put_contents($project . '/phpunit.xml', '<phpunit><source><include><directory>app</directory></include></source></phpunit>');

    $application->run(['test', '--no-parallel', '--filter=Order'], $project, $output, $error);

    expect($runner->command)->toBe([PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage', '--bootstrap=/sonda/bootstrap.php', '--filter=Order']);
});

it('hands the compare command its output directory and options, and reports a missing one', function (): void {
    [$application, $output, $error, $processes] = application();

    $status = $application->run(['compare', 'build/compare', '--processes=4', '--filter=Order'], '/project', $output, $error);
    $missing = $application->run(['compare', '--batch=80'], '/project', $output, $error);

    expect($status)->toBe(0)
        ->and($processes->command)->toBe(['bash', '/sonda/tools/compare-with-xdebug.sh', 'build/compare', '--filter=Order'])
        ->and($processes->environment)->toBe(['PROCESSES' => '4'])
        ->and($missing)->toBe(1)
        ->and(contents($error))->toContain('O comando compare precisa do diretório de saída: sonda compare <saída>.');
});
