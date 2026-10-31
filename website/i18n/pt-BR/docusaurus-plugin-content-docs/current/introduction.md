---
title: Introdução
slug: /
sidebar_position: 1
description: O que é o Sonda, por que existe e o que ele dá a você.
---

Cobertura de linhas e de branches para PHP por instrumentação do fonte: sem Xdebug, sem PCOV, a
suíte roda no tempo normal dela, e os branches são contados com uma semântica estrita, que pede os
dois desfechos de cada decisão.

## Por quê

Cobertura de branches em PHP tem uma fonte hoje, o Xdebug com `--path-coverage`, e ela é lenta e
pesada: custa cerca de 30 vezes o tempo da suíte, e o php-code-coverage acumula, a cada teste, os
ids de teste em todos os branches e caminhos dos arquivos carregados, então o custo por teste cresce
ao longo da rodada. Numa suíte Laravel real, não terminou em uma hora. O PCOV é rápido, mas só conta
linhas, e os dois precisam de uma extensão instalada em cada máquina que mede.

O Sonda insere sondas no código antes de carregá-lo, como o Istanbul faz no JavaScript. Cada sonda
é uma atribuição num array; não há interceptação de opcodes nem enumeração de caminhos, que é o que
deixa o Xdebug lento e pesado. Os números dele foram validados contra o path coverage do Xdebug numa
suíte real inteira (veja [Comparação com o Xdebug](comparison-with-xdebug.md)).

## O que você ganha

- **Linhas e branches**, da mesma rodada, com relatórios em HTML, Clover, Cobertura, `.cov` e
  Code Quality do GitLab, montados pelo php-code-coverage.
- **Sem extensão**: o Sonda é uma dependência do Composer, ou um clone no PATH, e usa o PHPUnit,
  o php-code-coverage e o php-parser do projeto medido.
- **A suíte no tempo normal dela**: medir custa segundos, não múltiplos da suíte.
- **Semântica estrita de branches**: todo `if` sem `else` precisa dos dois desfechos testados, `?->`
  conta os dois lados, `&&` e `||` precisam do curto-circuito. Veja [Semântica dos branches](branch-semantics.md).
- **Views do Blade** medidas linha a linha e branch a branch, em projetos Laravel. Veja
  [Views do Blade](blade-views.md).
- **Feito para o CI**: `--changed` mede só o que o merge request tocou e exige 100% disso;
  `--affected` roda só os testes que executam o que mudou. Veja
  [Só o que mudou](usage/only-what-changed.md) e [Só os testes afetados](usage/affected-tests.md).

## Os números principais

A suíte de um projeto Laravel 13 com Filament, Pest 4 e Postgres, 4.514 testes:

| Rodada | Tempo | Memória |
|---|---|---|
| Sem cobertura | 178s | 308 MB |
| Sonda | 174s | 317 MB |
| Xdebug, só linhas | 671s | 640 MB |
| Xdebug com `--path-coverage` | não terminou em 63 min | mais de 760 MB |

Com `--parallel` (10 workers): 42s sem cobertura, 40s com o Sonda. A tabela inteira, os resultados
de fidelidade e como reproduzir a comparação no seu projeto estão em
[Comparação com o Xdebug](comparison-with-xdebug.md).

## Por onde seguir

- [Começando](getting-started.md): requisitos, instalação, primeira rodada.
- [Referência da CLI](usage/cli-reference.md): todas as opções.
- [No CI](ci/gitlab.md): receitas para GitLab e GitHub Actions.
- [Como funciona](how-it-works.md): sondas, cache, autoloader, workers.
