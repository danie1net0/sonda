<?php

declare(strict_types=1);

use Sonda\Project\DirectRequire;

it('records where a file of the scope is required and which file it is', function (): void {
    $require = new DirectRequire('/app/Boot.php', 3, '/app/helpers.php');

    expect($require->file)->toBe('/app/Boot.php')
        ->and($require->line)->toBe(3)
        ->and($require->target)->toBe('/app/helpers.php');
});
