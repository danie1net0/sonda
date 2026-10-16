<?php

declare(strict_types=1);

use Sonda\Cli\{CompareCommand, CompareOptions, ProcessRunner};

final class ComparingRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    /** @var array<string, string> */
    public array $environment = [];

    public string $directory = '';

    public function run(array $command, array $environment, string $directory): int
    {
        $this->command = $command;
        $this->environment = $environment;
        $this->directory = $directory;

        return 3;
    }
}

it('runs the comparison script of the sonda root in the project, with the knobs in the environment', function (): void {
    $processes = new ComparingRunner();

    $status = new CompareCommand($processes, '/sonda')->run(new CompareOptions('out', 6, 80, '/opt/xdebug.so', ['--filter=Order']), '/project');

    expect($status)->toBe(3)
        ->and($processes->command)->toBe(['bash', '/sonda/tools/compare-with-xdebug.sh', 'out', '--filter=Order'])
        ->and($processes->environment)->toBe(['PROCESSES' => '6', 'BATCH' => '80', 'XDEBUG_SO' => '/opt/xdebug.so'])
        ->and($processes->directory)->toBe('/project');
});

it('adds nothing to the environment without knobs', function (): void {
    $processes = new ComparingRunner();

    new CompareCommand($processes, '/sonda')->run(new CompareOptions('out'), '/project');

    expect($processes->environment)->toBe([]);
});
