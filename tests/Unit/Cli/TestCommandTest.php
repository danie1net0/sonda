<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Git\Git;
use Sonda\Project\TestMap;
use Sonda\Cli\{ProcessRunner, TestCommand, TestOptions};

/**
 * Stands in for the test runner: records the call and, like the bootstrap would, saves a run
 * of the project's Order.php in SONDA_OUT.
 */
final class FakeRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    /** @var array<string, string> */
    public array $environment = [];

    public string $directory = '';

    public int $calls = 0;

    /** What the generated configuration held, read while it still existed. */
    public string $generatedConfiguration = '';

    /**
     * @param array<int, int>                                                                                    $hits  probes of Order.php to report as hit
     * @param array<string, array{0: string, 1: 'small'|'medium'|'large'|'unknown', 2: bool, 3: float, 4: array<string, string>}> $tests what each test hit
     */
    public function __construct(private readonly int $status = 0, private readonly array $hits = [0 => 1, 1 => 1, 2 => 1], private readonly bool $savesRun = true, private readonly array $tests = [], private readonly array $problems = [])
    {
    }

    public function run(array $command, array $environment, string $directory): int
    {
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--configuration=' . $directory . '/.sonda-')) {
                $this->generatedConfiguration = (string) file_get_contents(substr($argument, strlen('--configuration=')));
            }
        }

        $this->command = $command;
        $this->environment = $environment;
        $this->directory = $directory;
        $this->calls++;

        if ($this->savesRun) {
            $order = $directory . '/app/Order.php';
            $probes = [0 => new Probe(7, true, 'Order->total', 7, 11), 1 => new Probe(8, true, 'Order->total', 8, 8), 2 => new Probe(11, false, 'Order->total', 0, 0)];
            new InstrumentationCache($environment['SONDA_CACHE'])->store('order', new InstrumentationResult('<?php', $probes));
            new Run($environment['SONDA_CACHE'], ['o' => [$order, 'order']], ['o' => $this->hits], $this->tests, $this->problems)->saveIn($environment['SONDA_OUT']);
        }

        return $this->status;
    }
}

/** A Laravel-less project: vendor/bin/phpunit, phpunit.xml with <source>, app/Order.php and app/Providers. */
function fakeProject(string $configuration = ''): string
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/vendor/bin', 0777, true);
    mkdir($root . '/app/Providers', 0777, true);
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/vendor/bin/phpunit', '');
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    file_put_contents($root . '/app/Providers/AppProvider.php', "<?php\n\nfinal class AppProvider\n{\n}\n");
    file_put_contents($root . '/phpunit.xml', $configuration !== '' ? $configuration : <<<'XML'
    <phpunit bootstrap="vendor/autoload.php">
        <source>
            <include><directory>app</directory></include>
            <exclude><directory>app/Providers</directory></exclude>
        </source>
    </phpunit>
    XML);

    return $root;
}

/** @return array{0: int, 1: string} the exit code and the output */
function runTestCommand(FakeRunner $runner, string $root, TestOptions $options, string $temporaryRoot): array
{
    $output = fopen('php://memory', 'w+');

    if ($output === false) {
        throw new RuntimeException('No memory for the output.');
    }

    $status = new TestCommand($runner, '/opt/sonda', $temporaryRoot . '/sonda')->run($options, $root, $output);
    rewind($output);

    return [$status, (string) stream_get_contents($output)];
}

it('runs the project tests instrumented and reports the coverage of its source', function (): void {
    $root = fakeProject();
    $temporary = TemporaryDirectory::create();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--filter=Order']), $temporary);

    $work = $temporary . '/sonda/' . substr(sha1($root), 0, 12);

    expect($status)->toBe(0)
        ->and($runner->command)->toBe([PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage', '--bootstrap=/opt/sonda/bootstrap.php', '--filter=Order'])
        ->and($runner->directory)->toBe($root)
        ->and($runner->environment)->toBe([
            'SONDA_AUTOLOAD' => $root . '/vendor/autoload.php',
            'SONDA_TARGET' => $root . '/app',
            'SONDA_VIEWS' => '',
            'SONDA_EXCLUDE' => $root . '/app/Providers',
            'SONDA_ONLY' => '',
            'SONDA_CACHE' => $work . '/cache',
            'SONDA_OUT' => $runner->environment['SONDA_OUT'],
            'SONDA_STRICT' => '1',
            'SONDA_FAST' => '1',
            'SONDA_CHAIN' => '',
            'SONDA_INCLUDES' => '0',
        ])
        ->and($runner->environment['SONDA_OUT'])->toMatch('#^' . preg_quote($work . '/runs-', '#') . '[0-9a-f]{8}$#')
        ->and(is_dir($runner->environment['SONDA_OUT']))->toBeFalse()
        ->and(fileperms($temporary . '/sonda') & 0o777)->toBe(0o700)
        ->and(fileperms($work) & 0o777)->toBe(0o700)
        ->and($output)->toBe("\nCoverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\n");
});

it('chains the project bootstrap when it is not the autoload, and honours the flags', function (): void {
    $root = fakeProject('<phpunit bootstrap="tests/bootstrap.php"><source><include><directory>app</directory></include></source></phpunit>');
    mkdir($root . '/tests');
    file_put_contents($root . '/tests/bootstrap.php', '<?php');
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--no-strict', '--no-fast', '--no-parallel']), TemporaryDirectory::create());

    expect($runner->environment)
        ->toHaveKey('SONDA_CHAIN', $root . '/tests/bootstrap.php')
        ->toHaveKey('SONDA_STRICT', '0')
        ->toHaveKey('SONDA_FAST', '0')
        ->toHaveKey('SONDA_EXCLUDE', '');
});

it('uses the configuration file given, relative to the project', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/phpunit.ci.xml', '<phpunit><source><include><directory>app/Providers</directory></include></source></phpunit>');
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['-c', 'phpunit.ci.xml']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_TARGET', $root . '/app/Providers')
        ->and($runner->command)->toContain('-c', 'phpunit.ci.xml');
});

it('measures only the given files and fails on what they leave uncovered', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/app/Order.php')
        ->and($output)->toBe("\nCoverage: lines 2/3 (66.67%), branches 1/2 (50.00%)\nUncovered:\napp/Order.php: lines 8; branches 8\n");
});

it('passes when the given files are fully covered', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--files=' . $root . '/app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("The changed files are 100% covered.\n");
});

it('runs nothing when none of the given files is in the source', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Providers/AppProvider.php,lib/Other.php']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->calls)->toBe(0)
        ->and($output)->toBe("No changed file inside <source>: nothing to measure.\n");
});

it('still writes the reports asked for, empty, when there is nothing to measure', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Providers/AppProvider.php', '--html=build/html', '--cobertura=build/cobertura.xml', '--clover=build/clover.xml', '--code-quality=build/quality/gl.json']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->calls)->toBe(0)
        ->and($output)->toBe("No changed file inside <source>: nothing to measure.\nHTML report in {$root}/build/html\nClover report in {$root}/build/clover.xml\nCobertura report in {$root}/build/cobertura.xml\nCode Quality report in {$root}/build/quality/gl.json\n")
        ->and((string) file_get_contents($root . '/build/quality/gl.json'))->toBe("[]\n")
        ->and($root . '/build/html/index.html')->toBeFile()
        ->and((string) file_get_contents($root . '/build/cobertura.xml'))->toContain('lines-valid="0"');
});

it('keeps the views in the whole-project run a floor asks for', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--files=app/Providers/AppProvider.php', '--min-lines=1']), TemporaryDirectory::create());

    expect($runner->calls)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/resources/views')
        ->toHaveKey('SONDA_VIEWS', $root . '/resources/views');
});

it('measures the whole project when nothing in scope changed but a floor was asked for', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Providers/AppProvider.php', '--min-lines=70', '--code-quality=gl.json']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->calls)->toBe(1)
        ->and($output)->toStartWith("No changed file inside <source>: with a coverage floor, the whole project is measured.\n")
        ->toContain("\nCoverage: lines 2/3 (66.67%), branches 1/2 (50.00%)\n")
        ->toContain("Coverage below the minimum: lines 66.67% < 70.00%\n")
        // The floor measured it all, but nothing changed for the merge request to point at.
        ->and((string) file_get_contents($root . '/gl.json'))->toBe("[]\n");
});

it('keeps the exit code of a failing test run', function (): void {
    $root = fakeProject();

    [$status] = runTestCommand(new FakeRunner(status: 2), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(2);
});

it('keeps the exit code of a failing test run over uncovered files', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(status: 2, hits: []), $root, TestOptions::parse(['--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(2)
        ->and($output)->toContain('Uncovered:');
});

it('fails when the run left no coverage data', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(savesRun: false), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($output)->toStartWith("\nNo coverage data: The directory ");

    [$status] = runTestCommand(new FakeRunner(status: 3, savesRun: false), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(3);
});

it('writes the reports asked for', function (): void {
    $root = fakeProject();

    [, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--html=build/html', '--clover=build/clover.xml', '--cov=' . $root . '/build/coverage.cov', '--cobertura=build/cobertura.xml']), TemporaryDirectory::create());

    expect(is_file($root . '/build/html/index.html'))->toBeTrue()
        ->and(is_file($root . '/build/clover.xml'))->toBeTrue()
        ->and(is_file($root . '/build/coverage.cov'))->toBeTrue()
        ->and((string) file_get_contents($root . '/build/cobertura.xml'))->toContain('<coverage ')
        ->and($output)->toContain("HTML report in {$root}/build/html\n")
        ->toContain("Clover report in {$root}/build/clover.xml\n")
        ->toContain(".cov report in {$root}/build/coverage.cov\n")
        ->toContain("Cobertura report in {$root}/build/cobertura.xml\n");
});

it('measures the files changed since a reference together with the ones given', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/Kept.php', "<?php\n");
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity; // changed\n    }\n}\n");
    file_put_contents($root . '/app/Extra.php', "<?php\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--files=app/Kept.php,app/Providers/AppProvider.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', implode(PATH_SEPARATOR, [$root . '/app/Extra.php', $root . '/app/Kept.php', $root . '/app/Order.php']));
});

/** The fake project under git, with the base commit done. */
function committedFakeProject(): string
{
    $root = fakeProject();
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);

    return $root;
}

it('passes when what changed is fully covered, whatever else the file leaves uncovered', function (): void {
    $root = committedFakeProject();
    // The class line changes: outside every method, so line 8 staying uncovered does not matter.
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order // changed\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("Of what changed: lines 0/0 (100.00%), branches 0/0 (100.00%)\nWhat changed is 100% covered.\n");
});

it('checks the whole method a change falls in and says how much of what changed is covered', function (): void {
    $root = committedFakeProject();
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity; // changed\n    }\n}\n");

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--changed=master']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("Coverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\nOf what changed: lines 3/3 (100.00%), branches 2/2 (100.00%)\nWhat changed is 100% covered.\n");
});

it('checks a file named explicitly whole even when its change touches no method', function (): void {
    $root = committedFakeProject();
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order // changed\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/app/Order.php')
        ->and($output)->toEndWith("Coverage: lines 2/3 (66.67%), branches 1/2 (50.00%)\nOf what changed: lines 2/3 (66.67%), branches 1/2 (50.00%)\nUncovered:\napp/Order.php: lines 8; branches 8\n");
});

it('runs several test paths in parallel through a configuration generated beside the project one, and removes it', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/vendor/bin/paratest', '');
    mkdir($root . '/tests/Unit', 0777, true);
    mkdir($root . '/tests/Feature');
    $one = new FakeRunner();
    $two = new FakeRunner();
    $configured = new FakeRunner();
    $series = new FakeRunner();

    runTestCommand($one, $root, TestOptions::parse(['tests/Unit']), TemporaryDirectory::create());
    runTestCommand($two, $root, TestOptions::parse(['--filter=x', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());
    runTestCommand($configured, $root, TestOptions::parse(['-c', 'phpunit.xml', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());
    runTestCommand($series, $root, TestOptions::parse(['--no-parallel', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());

    $generated = array_values(array_filter($two->command, static fn (string $argument): bool => str_starts_with($argument, '--configuration=')));

    expect($one->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($one->command, -1))->toBe(['tests/Unit'])
        ->and($two->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($two->command, -2))->toEqual(['--filter=x', $generated[0] ?? ''])
        ->and($generated[0] ?? '')->toMatch('#^--configuration=' . preg_quote($root, '#') . '/\\.sonda-[0-9a-f]{8}\\.phpunit\\.xml$#')
        ->and($two->generatedConfiguration)->toContain('<directory suffix="Test.php">' . $root . '/tests/Unit</directory>')
        ->and(glob($root . '/.sonda-*'))->toBe([])
        ->and($configured->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($configured->command, -1)[0])->toStartWith('--configuration=' . $root . '/.sonda-')
        ->and($series->command[1])->toBe('vendor/bin/phpunit')
        ->and(array_slice($series->command, -2))->toBe(['tests/Unit', 'tests/Feature']);
});

/** The fake project as a Laravel one: an artisan file and Blade views. */
function laravelProject(): string
{
    $root = fakeProject();
    mkdir($root . '/resources/views/mail', 0777, true);
    file_put_contents($root . '/artisan', '<?php');
    file_put_contents($root . '/resources/views/home.blade.php', "<h1>{{ \$title }}</h1>\n");
    file_put_contents($root . '/resources/views/mail/welcome.blade.php', "<p>{{ \$name }}</p>\n");
    file_put_contents($root . '/resources/views/plain.php', "<?php echo 'not blade';\n");

    return $root;
}

it('measures the Blade views of a Laravel project along with its source', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/resources/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/resources/views')
        ->and($output)->toContain("Coverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\n");
});

it('narrows to a Blade view named explicitly, and not to a plain PHP file under the views', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--files=resources/views/home.blade.php,resources/views/plain.php']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/resources/views/home.blade.php');
});

it('measures the views of the packages and modules kept in the project, not of its dependencies', function (): void {
    $root = laravelProject();
    mkdir($root . '/packages/ui/resources/views', 0777, true);
    mkdir($root . '/vendor/acme/pkg/resources/views', 0777, true);
    file_put_contents($root . '/packages/ui/resources/views/button.blade.php', "<button>{{ \$label }}</button>\n");
    file_put_contents($root . '/vendor/acme/pkg/resources/views/x.blade.php', "<p>{{ \$x }}</p>\n");
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/packages/ui/resources/views' . PATH_SEPARATOR . $root . '/resources/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/packages/ui/resources/views' . PATH_SEPARATOR . $root . '/resources/views');
});

it('measures the views of the directories given with --views, relative to the project or absolute, each once', function (): void {
    $root = fakeProject();
    mkdir($root . '/app/views', 0777, true);
    file_put_contents($root . '/app/views/page.blade.php', "<p>{{ \$x }}</p>\n");
    // A source file whose name starts like the views directory is still a source file.
    file_put_contents($root . '/app/views.php', "<?php\n\necho 1;\n");
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--views=app/views,' . $root . '/app/views']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/app/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/app/views')
        ->and($output)->toContain('Coverage: lines 3/4 (75.00%)');
});

it('refuses a --views directory that does not exist', function (): void {
    $root = fakeProject();

    expect(fn (): array => runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--views=themes/none']), TemporaryDirectory::create()))
        ->toThrow(InvalidArgumentException::class, 'The views directory themes/none does not exist.');
});

it('hands the workers real paths, even when the temporary root is reached through a symlink', function (): void {
    $root = laravelProject();
    $real = TemporaryDirectory::create();
    $link = TemporaryDirectory::create() . '/link';
    symlink($real, $link);
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse([]), $link);

    expect($runner->environment['SONDA_CACHE'])->toStartWith(realpath($real) . '/')
        ->and($runner->environment['SONDA_OUT'])->toStartWith(realpath($real) . '/');
});

it('leaves the views out with --no-blade', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--no-blade']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_VIEWS', '')
        ->toHaveKey('SONDA_TARGET', $root . '/app');
});

it('refuses to work under a directory open to other users, before running anything', function (string $shared): void {
    $root = fakeProject();
    $temporary = TemporaryDirectory::create();
    $work = $temporary . '/sonda/' . substr(sha1($root), 0, 12);
    mkdir($temporary . '/sonda', $shared === 'root' ? 0o755 : 0o700);

    if ($shared === 'project') {
        mkdir($work, 0o755);
    }

    $runner = new FakeRunner();
    $refused = $shared === 'root' ? $temporary . '/sonda' : $work;

    expect(fn (): array => runTestCommand($runner, $root, TestOptions::parse([]), $temporary))
        ->toThrow(RuntimeException::class, "The directory {$refused} must belong to the current user and be private: chmod 700 on it fixes that.")
        ->and($runner->calls)->toBe(0);
})->with(['root', 'project']);

it('lists the coverage of each file with --text', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--text']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toContain("\nCoverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\napp/Order.php: lines 3/3 (100.00%), branches 2/2 (100.00%)\n");
});

it('fails below the floors of --min-lines and --min-branches, saying which, and passes at them', function (): void {
    $root = fakeProject();

    [$below, $belowOutput] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=70', '--min-branches=50', '--html=build/html']), TemporaryDirectory::create());
    [$at] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=66.66', '--min-branches=50']), TemporaryDirectory::create());
    [$failedRun] = runTestCommand(new FakeRunner(status: 2, hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=70']), TemporaryDirectory::create());

    expect($below)->toBe(1)
        ->and($belowOutput)->toContain("Coverage below the minimum: lines 66.67% < 70.00%\n")
        ->toContain('HTML report in ')
        ->and($at)->toBe(0)
        ->and($failedRun)->toBe(2);
});

it('runs quietly when a file of the source requires another by its path, which is measured too', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction fake_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Boot.php', "<?php\n\nrequire __DIR__ . '/helpers.php';\n");
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->not->toContain('Aviso')
        ->and($runner->calls)->toBe(1);
});

it("refuses to run when a file of Composer's files autoload is also required directly, which would declare it twice", function (): void {
    $root = fakeProject();
    mkdir($root . '/vendor/composer');
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction fake_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Boot.php', "<?php\n\nrequire_once __DIR__ . '/helpers.php';\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn ['h' => dirname(__DIR__, 2) . '/app/helpers.php'];\n");
    $runner = new FakeRunner();

    expect(fn (): array => runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create()))
        ->toThrow(RuntimeException::class, "app/helpers.php is in Composer's files key and is still loaded by a direct require in app/Boot.php:3: under Sonda it would be declared twice. Drop the require.")
        ->and($runner->calls)->toBe(0);
});

it('measures the whole project when a floor is asked for, and keeps the verdict on the files given', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/Extra.php', "<?php\n\nfunction extra(): int\n{\n    return 1;\n}\n");
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Order.php', '--min-lines=50']), TemporaryDirectory::create());
    [$narrowStatus, $narrowOutput] = runTestCommand($narrow = new FakeRunner(), $root, TestOptions::parse(['--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->environment['SONDA_ONLY'])->toBe('')
        ->and($output)->toContain("\nCoverage: lines 3/4 (75.00%), branches 2/2 (100.00%)\n")
        ->toContain("The changed files are 100% covered.\n")
        ->and($narrowStatus)->toBe(0)
        ->and($narrow->environment['SONDA_ONLY'])->toBe($root . '/app/Order.php')
        ->and($narrowOutput)->toContain("\nCoverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\n");
});

it('fails the floor on the whole project even when the files given are all covered', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/Extra.php', "<?php\n\nfunction extra(): int\n{\n    return 1;\n}\n");

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--files=app/Order.php', '--min-lines=80']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($output)->toContain("Coverage below the minimum: lines 75.00% < 80.00%\n")
        ->toContain("The changed files are 100% covered.\n");
});

/**
 * The fake project committed, with a test suite of tests/OrderTest.php and tests/OtherTest.php,
 * and Order.php changed since master.
 */
function affectedFakeProject(): string
{
    $root = fakeProject(<<<'XML'
    <phpunit bootstrap="vendor/autoload.php">
        <testsuites><testsuite name="Unit"><directory>tests</directory></testsuite></testsuites>
        <source>
            <include><directory>app</directory></include>
            <exclude><directory>app/Providers</directory></exclude>
        </source>
    </phpunit>
    XML);
    mkdir($root . '/tests');
    touch($root . '/tests/OrderTest.php');
    touch($root . '/tests/OtherTest.php');
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);
    file_put_contents($root . '/app/Order.php', str_replace('return $quantity;', 'return $quantity; // changed', (string) file_get_contents($root . '/app/Order.php')));

    return $root;
}

/** Where the command keeps the test map of a project, under a temporary root. */
function testMapPath(string $temporaryRoot, string $root): string
{
    $directory = $temporaryRoot . '/sonda/' . substr(sha1($root), 0, 12);
    mkdir($directory, 0o700, true);
    chmod($temporaryRoot . '/sonda', 0o700);

    return $directory . '/tests.map';
}

it('runs only the test files the map says run what changed', function (): void {
    $root = affectedFakeProject();
    $temporaryRoot = TemporaryDirectory::create();
    new TestMap([$root . '/tests/OrderTest.php' => [$root . '/app/Order.php'], $root . '/tests/OtherTest.php' => []])->save(testMapPath($temporaryRoot, $root), $root);
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--affected', '--no-parallel']), $temporaryRoot);

    expect($status)->toBe(0, $output)
        ->and($output)->toStartWith("Running only the affected tests: tests/OrderTest.php\n")
        ->and(array_slice($runner->command, -1))->toBe([$root . '/tests/OrderTest.php'])
        ->and($runner->environment['SONDA_ONLY'])->toBe($root . '/app/Order.php');
});

it('runs the whole suite and measures the whole project when the map cannot tell, and saves what the run learned', function (): void {
    $root = affectedFakeProject();
    $temporaryRoot = TemporaryDirectory::create();
    $mapPath = testMapPath($temporaryRoot, $root);
    $runner = new FakeRunner(tests: ['OrderTest::test' => [$root . '/tests/OrderTest.php', 'unknown', true, 0.0, ['o' => pack('V', 0)]]]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--affected', '--no-parallel']), $temporaryRoot);

    expect($status)->toBe(0, $output)
        ->and($output)->toStartWith("Running the whole suite and measuring the whole project: this project has no test map yet; this run creates it.\n")
        ->and($runner->command)->not->toContain($root . '/tests/OrderTest.php')
        ->and($runner->environment['SONDA_ONLY'])->toBe('')
        ->and(TestMap::load($mapPath, $root)->sources)->toBe([$root . '/tests/OrderTest.php' => [$root . '/app/Order.php']]);
});

it('leaves --affected aside with a floor or with the tests to run given', function (): void {
    $root = affectedFakeProject();
    $temporaryRoot = TemporaryDirectory::create();
    new TestMap([$root . '/tests/OrderTest.php' => [$root . '/app/Order.php']])->save(testMapPath($temporaryRoot, $root), $root);
    $floorRunner = new FakeRunner();
    $pathRunner = new FakeRunner();

    [, $withFloor] = runTestCommand($floorRunner, $root, TestOptions::parse(['--changed=master', '--affected', '--min-lines=1']), $temporaryRoot);
    [, $withPath] = runTestCommand($pathRunner, $root, TestOptions::parse(['--changed=master', '--affected', 'tests/OtherTest.php']), $temporaryRoot);

    expect($withFloor)->toStartWith("--affected ignored: with a coverage floor, the whole suite runs.\n")
        ->and($floorRunner->command)->not->toContain($root . '/tests/OrderTest.php')
        ->and($withPath)->toStartWith("--affected ignored: the tests to run were given.\n")
        ->and(array_slice($pathRunner->command, -1))->toBe(['tests/OtherTest.php']);
});

it('takes the files given as what changed when there is no reference', function (): void {
    $root = affectedFakeProject();
    $temporaryRoot = TemporaryDirectory::create();
    new TestMap([$root . '/tests/OrderTest.php' => [$root . '/app/Order.php'], $root . '/tests/OtherTest.php' => []])->save(testMapPath($temporaryRoot, $root), $root);
    $runner = new FakeRunner();

    [, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Order.php', '--affected', '--no-parallel']), $temporaryRoot);

    expect($output)->toStartWith("Running only the affected tests: tests/OrderTest.php\n")
        ->and(array_slice($runner->command, -1))->toBe([$root . '/tests/OrderTest.php']);
});

it('counts the affected test files instead of naming them when they are more than ten', function (): void {
    $root = affectedFakeProject();
    $temporaryRoot = TemporaryDirectory::create();
    $sources = [];

    foreach (range(1, 12) as $number) {
        $sources[$root . "/tests/Order{$number}Test.php"] = $number <= 11 ? [$root . '/app/Order.php'] : [];
    }

    new TestMap($sources)->save(testMapPath($temporaryRoot, $root), $root);

    [, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--changed=master', '--affected', '--no-parallel']), $temporaryRoot);

    expect($output)->toStartWith("Running only the affected tests: 11 of 12 test files\n");
});

it('reports the gaps of the whole project for GitLab Code Quality when nothing narrows the check', function (): void {
    $root = fakeProject();

    [, $output] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--code-quality=gl.json']), TemporaryDirectory::create());
    $issues = json_decode((string) file_get_contents($root . '/gl.json'), true);

    expect($output)->toEndWith("Code Quality report in {$root}/gl.json\n")
        ->and(array_column($issues, 'description'))->toBe(['Line 8 uncovered: no test runs it.', 'Branch at line 8 uncovered: some outcome of this decision was never tested.'])
        ->and($issues[0]['location']['path'])->toBe('app/Order.php');
});

it('reports only the gaps of what changed, with paths from the root of the repository the project sits in', function (): void {
    $repository = TemporaryDirectory::create();
    $root = fakeProject();
    rename($root, $repository . '/project');
    $root = $repository . '/project';
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);
    file_put_contents($root . '/app/Order.php', str_replace('return $quantity;', 'return $quantity; // changed', (string) file_get_contents($root . '/app/Order.php')));

    [$status] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--changed=master', '--code-quality=gl.json']), TemporaryDirectory::create());
    $issues = json_decode((string) file_get_contents($root . '/gl.json'), true);

    expect($status)->toBe(1)
        ->and(array_column(array_column($issues, 'location'), 'path'))->toBe(['project/app/Order.php', 'project/app/Order.php']);
});

it('keeps the test map where --map says, relative to the project', function (): void {
    $root = affectedFakeProject();
    $runner = new FakeRunner(tests: ['OrderTest::test' => [$root . '/tests/OrderTest.php', 'unknown', true, 0.0, ['o' => pack('V', 0)]]]);

    runTestCommand($runner, $root, TestOptions::parse(['--map=.cache/sonda/tests.map']), TemporaryDirectory::create());
    [, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--changed=master', '--affected', '--map=.cache/sonda/tests.map', '--no-parallel']), TemporaryDirectory::create());

    expect(TestMap::load($root . '/.cache/sonda/tests.map', $root)->sources)->toBe([$root . '/tests/OrderTest.php' => [$root . '/app/Order.php']])
        ->and($output)->toStartWith("Running only the affected tests: tests/OrderTest.php\n");
});

it('warns when the details of some test could not be read, keeping the coverage', function (): void {
    [$status, $output] = runTestCommand(new FakeRunner(problems: ['Cannot access private property']), fakeProject(), new TestOptions(), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toContain("Coverage: lines 3/3 (100.00%), branches 2/2 (100.00%)\nWarning: Sonda could not read the file or the size of some test; the coverage is the same, but --affected and --covered-by may come out incomplete: Cannot access private property\n");
});

it('points at nothing in Code Quality when what changed since the reference is outside the source', function (): void {
    $root = committedFakeProject();
    file_put_contents($root . '/app/Providers/AppProvider.php', "<?php\n\nfinal class AppProvider // changed\n{\n}\n");

    [, $output] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--changed=master', '--min-lines=1', '--code-quality=gl.json']), TemporaryDirectory::create());

    expect($output)->toStartWith("No changed file inside <source>: with a coverage floor, the whole project is measured.\n")
        ->and((string) file_get_contents($root . '/gl.json'))->toBe("[]\n");
});

it('instruments the files included by their path when the source has files the autoloader cannot load, or when asked', function (): void {
    $root = fakeProject();
    $automatic = new FakeRunner();
    runTestCommand($automatic, $root, new TestOptions(), TemporaryDirectory::create());
    file_put_contents($root . '/app/migration.php', "<?php\n\nreturn new class () {\n};\n");
    $detected = new FakeRunner();
    runTestCommand($detected, $root, new TestOptions(), TemporaryDirectory::create());
    $refused = new FakeRunner();
    runTestCommand($refused, $root, TestOptions::parse(['--no-includes']), TemporaryDirectory::create());
    unlink($root . '/app/migration.php');
    $asked = new FakeRunner();
    runTestCommand($asked, $root, TestOptions::parse(['--includes']), TemporaryDirectory::create());

    expect($automatic->environment['SONDA_INCLUDES'])->toBe('0')
        ->and($detected->environment['SONDA_INCLUDES'])->toBe('1')
        ->and($refused->environment['SONDA_INCLUDES'])->toBe('0')
        ->and($asked->environment['SONDA_INCLUDES'])->toBe('1');
});

it("leaves Composer's files out of what decides the includes, since the autoloader loads them", function (): void {
    $root = fakeProject();
    mkdir($root . '/vendor/composer');
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction fake_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn ['h' => dirname(__DIR__, 2) . '/app/helpers.php'];\n");
    $runner = new FakeRunner();

    runTestCommand($runner, $root, new TestOptions(), TemporaryDirectory::create());

    expect($runner->environment['SONDA_INCLUDES'])->toBe('0');
});
