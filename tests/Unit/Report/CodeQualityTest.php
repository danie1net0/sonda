<?php

declare(strict_types=1);

use Sonda\Report\CodeQuality;

it('reports each run of uncovered lines and each uncovered branch as an issue, with paths from the root', function (): void {
    $report = json_decode(CodeQuality::of([
        '/repo/app/Order.php' => ['lines' => [3, 4, 9], 'branches' => [[20, 23]]],
        '/elsewhere/Thing.php' => ['lines' => [], 'branches' => [[1, 1]]],
    ], '/repo/'), true);

    expect($report)->toBe([
        [
            'type' => 'issue',
            'check_name' => 'sonda-linha',
            'description' => 'Linhas 3-4 sem cobertura: nenhum teste a executa.',
            'categories' => ['Bug Risk'],
            'severity' => 'minor',
            'fingerprint' => md5('sonda-linha:app/Order.php:3:4'),
            'location' => ['path' => 'app/Order.php', 'lines' => ['begin' => 3, 'end' => 4]],
        ],
        [
            'type' => 'issue',
            'check_name' => 'sonda-linha',
            'description' => 'Linha 9 sem cobertura: nenhum teste a executa.',
            'categories' => ['Bug Risk'],
            'severity' => 'minor',
            'fingerprint' => md5('sonda-linha:app/Order.php:9:9'),
            'location' => ['path' => 'app/Order.php', 'lines' => ['begin' => 9, 'end' => 9]],
        ],
        [
            'type' => 'issue',
            'check_name' => 'sonda-branch',
            'description' => 'Branch da linha 20 sem cobertura: algum desfecho dessa decisão nunca foi testado.',
            'categories' => ['Bug Risk'],
            'severity' => 'minor',
            'fingerprint' => md5('sonda-branch:app/Order.php:20:23'),
            'location' => ['path' => 'app/Order.php', 'lines' => ['begin' => 20, 'end' => 23]],
        ],
        [
            'type' => 'issue',
            'check_name' => 'sonda-branch',
            'description' => 'Branch da linha 1 sem cobertura: algum desfecho dessa decisão nunca foi testado.',
            'categories' => ['Bug Risk'],
            'severity' => 'minor',
            'fingerprint' => md5('sonda-branch:/elsewhere/Thing.php:1:1'),
            'location' => ['path' => '/elsewhere/Thing.php', 'lines' => ['begin' => 1, 'end' => 1]],
        ],
    ]);
});

it('writes readable JSON, ending in a newline, and an empty list without gaps', function (): void {
    expect(CodeQuality::of(['/repo/a/ção.php' => ['lines' => [1], 'branches' => []]], '/repo'))
        ->toContain('"path": "a/ção.php"')
        ->toEndWith("]\n")
        ->and(CodeQuality::of([], '/repo'))->toBe("[]\n");
});
