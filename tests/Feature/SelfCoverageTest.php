<?php

declare(strict_types=1);

use Sonda\Cli\SystemProcessRunner;

it('measures its own classes in isolation and propagates the coverage verdict', function (string $filter, int $expectedStatus, string $expectedOutput): void {
    $root = dirname(__DIR__, 2);
    $temporary = TemporaryDirectory::create();
    $output = fopen('php://temp', 'w+');
    $command = [];
    $command[] = PHP_BINARY;
    $command[] = $root . '/tools/self-coverage.php';
    $command[] = '--no-parallel';
    $command[] = '--files=src/Edit.php';
    $command[] = '--';
    $command[] = 'tests/Unit/EditTest.php';
    $command[] = '--filter=' . $filter;
    $command[] = '--colors=never';
    $environment = [];
    $environment['TMPDIR'] = $temporary;

    $status = new SystemProcessRunner(STDIN, $output, $output)->run($command, $environment, $root);
    rewind($output);
    $text = stream_get_contents($output);
    fclose($output);

    expect($status)->toBe($expectedStatus, $text)
        ->and($text)->toContain($expectedOutput)
        ->and(glob($temporary . '/sonda-self-coverage-*'))->toBe([]);
})->with([
    'all paths' => ['.*', 0, 'The changed files are 100% covered.'],
    'missing paths' => ['creates opening edits', 1, 'Uncovered:'],
]);

it('keeps a restricted self-coverage scope out of the fixture subprocesses', function (): void {
    $root = dirname(__DIR__, 2);
    $output = fopen('php://temp', 'w+');
    $command = [];
    $command[] = PHP_BINARY;
    $command[] = $root . '/tools/self-coverage.php';
    $command[] = '--files=src/Edit.php';
    $command[] = '--';
    $command[] = 'tests/Unit/EditTest.php';
    $command[] = 'tests/Feature/EndToEndTest.php';
    $command[] = '--colors=never';
    $command[] = '--processes=2';

    $status = new SystemProcessRunner(STDIN, $output, $output)->run($command, [], $root);
    rewind($output);
    $text = stream_get_contents($output);
    fclose($output);

    expect($status)->toBe(0, $text)
        ->and($text)->toContain('9 passed')
        ->toContain('The changed files are 100% covered.');
});
