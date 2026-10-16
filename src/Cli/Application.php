<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;
use RuntimeException;

use function in_array;

final readonly class Application
{
    public const string USAGE = <<<'TEXT'
    uso: sonda test [opções] [argumentos do runner]

    Roda os testes do projeto da pasta atual com a instrumentação do Sonda e mostra a
    cobertura de linhas e de branches do <source> do phpunit.xml. Sem instalar nada no projeto.

    Opções:
      --changed=<ref>   mede só os arquivos de <source> alterados desde o merge-base com a
                        referência (commitados, modificados e novos); falha se sobrar algo descoberto
      --files=a,b       mede só esses arquivos; falha se sobrar algo descoberto
      --html=<dir>      grava o relatório HTML
      --clover=<arq>    grava o relatório Clover
      --cov=<arq>       grava o .cov do php-code-coverage
      --cobertura=<arq> grava o relatório Cobertura (o que o GitLab lê no MR)
      --no-strict       não exige o else de todo if nem o lado nulo de ?->
      --no-fast         sondas sem a referência local por função
      --no-parallel     não usa o ParaTest mesmo quando instalado
      --no-blade        não mede as views do Blade (resources/views) de um projeto Laravel
      --views=a,b       mede também as views do Blade dessas pastas
      -c <arquivo>      o phpunit.xml a usar, também repassado ao runner

    Qualquer outro argumento vai para o runner (php artisan test, pest ou phpunit).
    TEXT;

    public function __construct(private TestCommand $testCommand)
    {
    }

    /**
     * @param list<string> $arguments
     * @param resource     $output
     * @param resource     $error
     */
    public function run(array $arguments, string $projectRoot, $output, $error): int
    {
        $command = $arguments[0] ?? 'help';

        if (in_array($command, ['help', '--help', '-h'], true)) {
            fwrite($output, self::USAGE . "\n");

            return 0;
        }

        if ($command !== 'test') {
            fwrite($error, "Comando desconhecido: {$command}\n\n" . self::USAGE . "\n");

            return 1;
        }

        try {
            return $this->testCommand->run(TestOptions::parse(array_slice($arguments, 1)), $projectRoot, $output);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($error, $exception->getMessage() . "\n");

            return 1;
        }
    }
}
