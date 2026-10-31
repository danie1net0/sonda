---
title: Só o que mudou
sidebar_position: 3
description: Meça só os arquivos que um branch mudou e exija 100% deles.
---

`--changed` e `--files` restringem a medição a um conjunto de arquivos e transformam o resultado em
veredito: qualquer coisa que fique descoberta neles reprova a rodada.

## `--changed=<ref>`

```bash
sonda test --changed=origin/master
```

Mede só os arquivos do `<source>` alterados desde o `merge-base` com a referência: commitados,
modificados e novos. Num clone raso, sem história em comum para achar o `merge-base`, a própria
referência é a base, que é o que o CI entrega (`CI_MERGE_REQUEST_DIFF_BASE_SHA` no GitLab, veja
[GitLab](../ci/gitlab.md)).

O veredito exige 100% **do que mudou**: as linhas que o diff acrescentou ou trocou e os métodos e
funções em que elas caem, inteiros; arquivo novo é cobrado inteiro. Uma lacuna antiga em outro
método do mesmo arquivo não reprova. Uma view do Blade entra no veredito inteira, como um método:
uma linha alterada numa view cobra as linhas e os branches da view toda.

Quando sobra algo, o comando lista e sai com erro:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

Cada linha de `Uncovered:` diz o arquivo, as linhas nunca executadas (em faixas) e os branches nunca
tomados (pelas linhas da decisão). Quando não sobra nada, o código de saída é zero e o comando
imprime `What changed is 100% covered.`

Quando nenhum arquivo do `<source>` mudou, não há o que medir e o comando diz isso:

```
No changed file inside <source>: nothing to measure.
```

Esse é o uso pensado para o CI: um job que mede só o que o merge request tocou e exige 100% disso.
Com `--code-quality` e `--cobertura`, o GitLab marca as linhas cobertas no diff do MR e aponta, na
própria linha, o que ainda precisa de teste.

## `--files=a,b`

```bash
sonda test --files=app/Models/Order.php,app/Actions/PlaceOrder.php
```

Cobra os arquivos listados inteiros, mesmo quando também mudaram. O veredito e a saída são os mesmos
do `--changed`; quando não sobra nada, o comando imprime `The changed files are 100% covered.`

## Pisos para o projeto inteiro

`--min-lines=<n>` e `--min-branches=<n>` reprovam a rodada quando a cobertura do projeto inteiro
fica abaixo de n%. São um piso global, além dos 100% do que mudou:

```bash
sonda test --changed=origin/master --min-lines=99 --min-branches=98
```

Com piso, o Sonda instrumenta e mede o `<source>` inteiro mesmo com `--changed` ou `--files`, cujo
veredito continua olhando só os arquivos deles; o total impresso é o do projeto. Quando um piso não
é atingido:

```
Coverage below the minimum: lines 98.70% < 99.00%
```

Duas consequências do piso para ter em mente:

- Quando nenhum arquivo do `<source>` mudou, o projeto inteiro é medido mesmo assim, para o piso
  poder ser conferido: `No changed file inside <source>: with a coverage floor, the whole project is measured.`
- O [`--affected`](affected-tests.md) é deixado de lado, porque só a suíte inteira responde pelo
  projeto inteiro: `--affected ignored: with a coverage floor, the whole suite runs.`
