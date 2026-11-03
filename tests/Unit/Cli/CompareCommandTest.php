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

dataset('xdebug runners', [
    'ParaTest, without Pest' => ['paratest', '--processes=2 -c'],
    'Pest' => ['pest', '--parallel --processes=2 -c'],
]);

it('passes --parallel to the Xdebug runner only when it is Pest', function (string $runner, string $arguments): void {
    $project = sys_get_temp_dir() . '/sonda-compare-' . bin2hex(random_bytes(4));
    mkdir("{$project}/vendor/bin", recursive: true);
    file_put_contents("{$project}/phpunit.xml", '<phpunit><source><include><directory>src</directory></include></source></phpunit>');
    file_put_contents("{$project}/vendor/autoload.php", '<?php');
    file_put_contents("{$project}/xdebug.so", '');
    file_put_contents("{$project}/vendor/bin/{$runner}", '<?php file_put_contents(__DIR__ . "/../../arguments.txt", implode(" ", array_slice($argv, 1)));');
    chmod("{$project}/vendor/bin/{$runner}", 0o755);
    $script = escapeshellarg(dirname(__DIR__, 3) . '/tools/compare-with-xdebug.sh');

    shell_exec('cd ' . escapeshellarg($project) . " && XDEBUG_SO={$project}/xdebug.so PROCESSES=2 bash {$script} out tests > /dev/null 2>&1");

    expect((string) file_get_contents("{$project}/arguments.txt"))->toStartWith($arguments);

    shell_exec('rm -rf ' . escapeshellarg($project));
})->with('xdebug runners');

it('runs scripts that ship in the Composer package', function (): void {
    $root = escapeshellarg(dirname(__DIR__, 3));
    $archived = (string) shell_exec("git -C {$root} archive --worktree-attributes HEAD tools | tar -t");

    expect(explode("\n", trim($archived)))->toContain('tools/compare-with-xdebug.sh', 'tools/compare.php')
        ->not->toContain('tools/inspect.php');
});
