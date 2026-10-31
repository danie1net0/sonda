---
title: Contribuir
sidebar_position: 14
description: Como rodar as verificações, o que uma mudança precisa e o que um relato de bug precisa.
---

O Sonda é desenvolvido no [GitHub](https://github.com/danie1net0/sonda); issues e pull requests vão
para lá.

## Rodando as verificações

```bash
composer install
vendor/bin/pest                      # the suite
vendor/bin/pint --test               # code style
vendor/bin/phpstan analyse           # static analysis
composer check                       # pint, phpstan, rector (dry-run) and the suite, as CI runs them
```

A cobertura é medida com o path coverage do Xdebug, exatamente como o CI faz, porque as classes de
bootstrap do próprio Sonda não podem ser instrumentadas por ele:

```bash
XDEBUG_MODE=coverage vendor/bin/pest --path-coverage --coverage-text --min=100
```

Mutation testing na classe que você mexeu (plugin de mutação do Pest, precisa de um driver de
cobertura):

```bash
XDEBUG_MODE=coverage vendor/bin/pest --mutate --class='Sonda\Probe'
```

Outras ferramentas do repositório:

```bash
php tools/phpunit-for-installed-coverage.php   # phpunit.xml without the compatibility classes of the other php-code-coverage version, for the 100% coverage
php tools/lint-corpus.php vendor 1             # instruments a whole directory and checks that the PHP is still valid
php tools/compare.php xdebug.cov sonda.cov -v  # compares two .cov files line by line and method by method
```

Os testes em `tests/Feature` rodam o `bin/sonda` e o `bootstrap.php` sobre um projeto de exemplo,
com o PHPUnit e com o ParaTest.

## O que uma mudança precisa

- Testes para a mudança, no arquivo de teste da classe (um arquivo de teste por classe, espelhando
  `src/`).
- 100% das linhas e 100% dos branches no código que você acrescentou ou mudou, medidos com o comando
  acima. Código que parece inalcançável é removido, não excluído da cobertura.
- Mutation testing nas classes tocadas sem sobreviventes além dos mutantes equivalentes.
- Pint e PHPStan limpos.
- Mensagens de commit no formato [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix:`, `feat:`, `perf:`, `docs:`...). O semantic-release decide a próxima versão a partir delas:
  um `fix` é patch, um `feat` é minor, um rodapé `BREAKING CHANGE` é major.

## Relatando um bug

[Abra uma issue](https://github.com/danie1net0/sonda/issues/new/choose) com:

- a saída de `sonda --version`;
- a versão do PHP, o runner de testes (PHPUnit, Pest, ParaTest, `artisan test`) e a versão dele;
- o comando exato que você rodou e o que ele imprimiu;
- quando a própria instrumentação está errada (um arquivo que não carrega, uma linha contada
  errado), um arquivo PHP mínimo que reproduza o problema. `php tools/lint-corpus.php <pasta> 1`
  instrumenta uma pasta inteira e confere que o PHP continua válido, que é o jeito mais rápido de
  achar esse arquivo.

## Licença

MIT.
