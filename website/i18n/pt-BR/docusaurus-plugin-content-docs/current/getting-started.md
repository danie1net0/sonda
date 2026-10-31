---
title: Começando
sidebar_position: 2
description: Requisitos, instalação pelo Composer ou por clone, e a primeira rodada.
---

O Sonda se instala como dependência de desenvolvimento, ou como um clone por máquina, e roda na raiz
do projeto que mede.

## Requisitos

- PHP 8.4 ou mais novo, com `git` no PATH para o `--changed`.
- No projeto medido: PHPUnit 11.5 (Pest 3) ou 12 (Pest 4), com o php-code-coverage da mesma
  versão, que trazem o `nikic/php-parser` que a instrumentação usa. O Sonda usa a fachada de eventos
  do PHPUnit, que é interna, e o CI roda a suíte nas duas versões, na mais baixa e na mais recente
  de cada uma.

## Instalação

Como dependência de desenvolvimento do projeto, que é o jeito de travar a versão e deixar o Renovate
ou o Dependabot atualizá-la:

```bash
composer require --dev ddr/sonda
vendor/bin/sonda test
```

Sem instalar nada no projeto medido: um clone por máquina, uma vez, e o comando no PATH. O Sonda usa
então as dependências do próprio projeto (PHPUnit, php-code-coverage, php-parser):

```bash
git clone https://github.com/danie1net0/sonda.git /opt/sonda
ln -s /opt/sonda/bin/sonda /usr/local/bin/sonda
```

Atualizar é `git -C /opt/sonda pull`.

`sonda --version` (ou `-V`) mostra a versão do sonda que está rodando: a que o Composer instalou, ou
a tag do checkout.

```bash
$ vendor/bin/sonda --version
sonda 1.16.0
```

## Primeira rodada

Na raiz do projeto:

```bash
sonda test
```

O Sonda lê o `<source>` (`include` e `exclude`) e o `bootstrap` do `phpunit.xml`, roda os testes com
`php artisan test --parallel` (ou `vendor/bin/pest`, `vendor/bin/paratest`, `vendor/bin/phpunit`, o
que houver), e imprime:

```
Coverage: lines 11140/11232 (99.18%), branches 4303/4371 (98.44%)
```

Qualquer argumento que o Sonda não conhece vai para o runner: `sonda test --filter=Order`,
`sonda test tests/Feature/Actions`. Vários caminhos (`sonda test tests/Unit tests/Feature/Actions`)
rodam em paralelo do mesmo jeito: o ParaTest só aceita um na linha de comando, então o Sonda os põe
num `phpunit.xml` gerado ao lado do do projeto, apagado no fim.

Com `--changed` ou `--files`, a saída diz também o que o veredito olhou e o que ficou descoberto, e o
comando sai com erro quando sobra algo:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

Quando não sobra nada:

```
Coverage: lines 121/121 (100.00%), branches 78/78 (100.00%)
The changed files are 100% covered.
```

`--text` lista a cobertura de cada arquivo depois do total, e `--html=<dir>` grava o relatório HTML
com cada linha e branch marcados. Todas as opções estão na [Referência da CLI](usage/cli-reference.md).

## Onde as coisas ficam

Cache e execuções ficam fora do projeto, em `$XDG_CACHE_HOME/sonda` ou `~/.cache/sonda`, num
diretório privado do usuário (modo 0700): as cópias instrumentadas rodam como código, então ninguém
mais pode escrever ali. O Sonda recusa um diretório de cache que pertença a outro usuário ou esteja
aberto a outros.
