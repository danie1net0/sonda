<?php

declare(strict_types=1);

use Sonda\Cli\TestOptions;

it('measures everything, strict, fast and in parallel by default', function (): void {
    expect(TestOptions::parse([]))->toEqual(new TestOptions());
});

it('reads its own options and passes the rest to the runner', function (): void {
    $options = TestOptions::parse([
        '--changed=origin/master', '--files=app/A.php,app/B.php', '--files=app/C.php', '--html=build/html', '--clover=clover.xml', '--cov=coverage.cov',
        '--no-strict', '--no-fast', '--no-parallel', '--filter=Order', 'tests/Feature',
    ]);

    expect($options)->toEqual(new TestOptions(
        changed: 'origin/master',
        files: ['app/A.php', 'app/B.php', 'app/C.php'],
        strict: false,
        fast: false,
        parallel: false,
        html: 'build/html',
        clover: 'clover.xml',
        cov: 'coverage.cov',
        runnerArguments: ['--filter=Order', 'tests/Feature'],
    ));
});

it('keeps the configuration file for itself and for the runner', function (): void {
    expect(TestOptions::parse(['-c', 'phpunit.ci.xml', '--filter=x']))
        ->configuration->toBe('phpunit.ci.xml')
        ->runnerArguments->toBe(['-c', 'phpunit.ci.xml', '--filter=x'])
        ->and(TestOptions::parse(['--configuration', 'a.xml']))
        ->configuration->toBe('a.xml')
        ->runnerArguments->toBe(['--configuration', 'a.xml'])
        ->and(TestOptions::parse(['--configuration=b.xml']))
        ->configuration->toBe('b.xml')
        ->runnerArguments->toBe(['--configuration=b.xml']);
});

it('passes everything after -- to the runner untouched', function (): void {
    expect(TestOptions::parse(['--no-fast', '--filter=x', '--', '--no-strict', '--changed=x']))
        ->fast->toBeFalse()
        ->strict->toBeTrue()
        ->changed->toBeNull()
        ->runnerArguments->toBe(['--filter=x', '--no-strict', '--changed=x']);
});

it('ignores empty entries in the file list', function (): void {
    expect(TestOptions::parse(['--files=a.php,,b.php,']))->files->toBe(['a.php', 'b.php']);
});

it('passes an option it knows only with a value to the runner when it comes bare', function (): void {
    expect(TestOptions::parse(['--changed', '--html']))
        ->changed->toBeNull()
        ->runnerArguments->toBe(['--changed', '--html']);
});

it('requires a value for the options that take one', function (string $argument, string $message): void {
    expect(fn (): TestOptions => TestOptions::parse([$argument]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['--changed=', 'A opção --changed precisa de um valor.'],
    ['--html=', 'A opção --html precisa de um valor.'],
    ['-c', 'A opção -c precisa de um arquivo.'],
]);
