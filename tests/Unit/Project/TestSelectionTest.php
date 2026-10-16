<?php

declare(strict_types=1);

use Sonda\Project\TestSelection;

it('tells the test paths from the options, leaving the configuration file out', function (): void {
    $selection = TestSelection::of(['--filter=x', 'tests/Unit', '-c', 'phpunit.xml', 'tests/Feature/ATest.php', '--configuration', 'a.xml', '--configuration=b.xml', '--no-progress']);

    expect($selection->options)->toBe(['--filter=x', '--no-progress'])
        ->and($selection->paths)->toBe(['tests/Unit', 'tests/Feature/ATest.php']);
});

it('finds no paths in options alone', function (): void {
    expect(TestSelection::of(['--filter=x']))->toEqual(new TestSelection(['--filter=x'], []))
        ->and(TestSelection::of([]))->toEqual(new TestSelection([], []));
});
