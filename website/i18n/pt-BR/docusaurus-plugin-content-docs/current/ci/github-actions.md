---
title: GitHub Actions
sidebar_position: 12
description: Um workflow de pull request que mede só o que o PR mudou e guarda o relatório HTML.
---

Nenhuma extensão é necessária no runner: o Sonda vem com o `composer install`, e `coverage: none`
deixa o Xdebug e o PCOV fora do caminho.

## O workflow

```yaml
name: coverage

on:
  pull_request:

jobs:
  coverage:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # the whole history, so Sonda finds the merge-base with the base branch

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none   # no Xdebug, no PCOV: Sonda instruments the source

      - run: composer install --prefer-dist --no-progress --no-interaction

      - run: vendor/bin/sonda test --changed=origin/${{ github.base_ref }} --html=coverage/html

      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: coverage-html
          path: coverage/html
```

O que cada peça faz:

- **`fetch-depth: 0`** busca a história inteira, para `--changed=origin/<branch base>` achar o
  `merge-base` entre o PR e a base dele e fazer o diff a partir dali: arquivos do `<source>`
  commitados, modificados e novos. Com o checkout raso padrão não há história em comum; aí passe o
  próprio commit da base (`--changed=${{ github.event.pull_request.base.sha }}`) depois de buscá-lo.
- **`coverage: none`** no `setup-php` deixa o Xdebug e o PCOV desligados. O Sonda não precisa
  deles, e um Xdebug carregado em modo de cobertura só deixaria a suíte mais lenta.
- **`--changed`** mede só os arquivos que o PR mudou e reprova o job quando uma linha alterada ou
  um método tocado fica descoberto; arquivo novo é cobrado inteiro. A saída lista o que falta. Veja
  [Só o que mudou](../usage/only-what-changed.md).
- **`--html`** grava o relatório; `if: always()` o envia também quando o job falhou, que é quando
  ele importa.

Para um piso no projeto inteiro, acrescente `--min-lines=<n> --min-branches=<n>`; para rodar só os
testes que executam o que mudou, acrescente `--affected --map=.sonda/tests.map` e guarde o
`.sonda/tests.map` com `actions/cache` entre as rodadas. Veja
[Só os testes afetados](../usage/affected-tests.md).

## Clover para outras ferramentas

Serviços que leem Clover (Codecov, Coveralls e afins) recebem `--clover=coverage/clover.xml` da
mesma rodada; `--cov=<arquivo>` grava o formato próprio do php-code-coverage, que serve a qualquer
outro.
