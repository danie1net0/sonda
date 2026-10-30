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
      --code-quality=<arq> grava o que ficou descoberto como Code Quality do GitLab (apontado no diff)
      --no-strict       não exige o else de todo if nem o lado nulo de ?->
      --no-fast         sondas sem a referência local por função
      --no-parallel     não usa o ParaTest mesmo quando instalado
      --no-blade        não mede as views do Blade (resources/views) de um projeto Laravel
      --views=a,b       mede também as views do Blade dessas pastas
      --text            lista a cobertura de cada arquivo
      --affected        com --changed ou --files, roda só os testes que executam o que mudou,
                        pelo mapa que cada execução grava; sem mapa ou com mudança que ele não
                        explica (config, rotas, arquivo novo), roda a suíte inteira
      --includes        mede também os arquivos carregados por include/require direto (padrão:
                        só quando o source tem arquivos sem classe, como migrations)
      --no-includes     não mede esses arquivos
      --map=<arq>       guarda o mapa de testes do --affected nesse arquivo (para o cache do CI)
      --covered-by      diz nos relatórios que testes executaram cada linha e branch (pesa:
                        num projeto grande, o HTML passa de 1 GB)
      --min-lines=<n>   falha se a cobertura de linhas do projeto ficar abaixo de n%
      --min-branches=<n> o mesmo para branches
      -c <arquivo>      o phpunit.xml a usar, também repassado ao runner

    sonda --version mostra a versão deste sonda.

    Qualquer outro argumento vai para o runner (php artisan test, pest ou phpunit).

    uso: sonda compare <saída> [opções] [argumentos do runner]

    Mede o projeto com o Sonda e com o Xdebug (path coverage) e compara os dois, gravando
    sonda.cov, xdebug.cov, compare.txt e summary.txt em <saída>. Precisa de um banco privado
    em DB_DATABASE no ambiente e de uma máquina livre.

    Opções:
      --processes=<n>   workers do ParaTest, ou filas de lotes (padrão 3)
      --batch=<n>       testes por lote do lado Xdebug, que numa suíte grande não termina de uma vez
      --xdebug=<so>     o xdebug.so a carregar
    TEXT;

    public function __construct(private TestCommand $testCommand, private CompareCommand $compareCommand, private string $version)
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

        if (in_array($command, ['--version', '-V'], true)) {
            fwrite($output, "sonda {$this->version}\n");

            return 0;
        }

        if (! in_array($command, ['test', 'compare'], true)) {
            fwrite($error, "Comando desconhecido: {$command}\n\n" . self::USAGE . "\n");

            return 1;
        }

        try {
            if ($command === 'compare') {
                return $this->compareCommand->run(CompareOptions::parse(array_slice($arguments, 1)), $projectRoot);
            }

            return $this->testCommand->run(TestOptions::parse(array_slice($arguments, 1)), $projectRoot, $output);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite($error, $exception->getMessage() . "\n");

            return 1;
        }
    }
}
