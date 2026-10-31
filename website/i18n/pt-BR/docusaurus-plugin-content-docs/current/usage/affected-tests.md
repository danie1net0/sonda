---
title: Só os testes afetados
sidebar_position: 4
description: Rode só os testes que executam o que mudou, pelo mapa de testes que cada rodada grava.
---

`--affected` transforma o veredito do `--changed` ou do `--files` numa rodada de segundos: rodam só
os arquivos de teste que executam o código alterado.

## `--affected`

```bash
sonda test --changed=origin/master --affected
```

Cada rodada grava, fora do projeto, um mapa de quais arquivos do `<source>` os testes de cada arquivo
de teste executaram. Com `--affected`, junto do `--changed` ou do `--files`, o Sonda roda só os
arquivos de teste que executam o que mudou, mais os arquivos de teste que mudaram. Os testes que
cobrem o código alterado são todos esses, então o veredito é o mesmo, em segundos em vez da suíte
inteira. O comando diz o que está rodando:

```
Running only the affected tests: tests/Unit/Actions/PlaceOrderTest.php
```

Com muitos arquivos de teste, ele os conta: `Running only the affected tests: 11 of 12 test files`.

`--affected` precisa de `--changed` ou `--files`: são eles que dizem o que mudou. Sozinho, é
recusado com `The option --affected needs --changed or --files: they say what changed.`

## Quando a suíte inteira roda mesmo assim

O mapa não responde por tudo, e aí a suíte inteira roda, medindo o projeto todo para o mapa
aprender. O comando diz o motivo antes de rodar:

```
Running the whole suite and measuring the whole project: this project has no test map yet; this run creates it.
```

Os motivos:

| Motivo impresso | Quando |
|---|---|
| `this project has no test map yet; this run creates it` | a primeira rodada, ou o arquivo do mapa sumiu |
| `no known test runs <path>` | mudou um arquivo do `<source>` que nenhum teste conhecido executa: um arquivo novo, ou um nunca executado |
| `<path> changed and is neither a <source> file nor a test` | mudou qualquer arquivo fora do `<source>` e dos testes (configuração, rotas, migrations, `composer.lock`, um helper de teste), porque qualquer teste pode lê-lo |

Dois outros casos deixam o `--affected` de lado sem consultar o mapa:

- Com piso de cobertura (`--min-lines`, `--min-branches`), a suíte inteira roda de qualquer jeito:
  `--affected ignored: with a coverage floor, the whole suite runs.`
- Com os testes a rodar já dados ao runner (`sonda test --changed=... --affected tests/Unit`):
  `--affected ignored: the tests to run were given.`

Um mapa desatualizado nunca deixa um teste afetado de fora: cada rodada regrava o que os arquivos de
teste que rodaram executam, e um arquivo do `<source>` que o mapa não conhece faz a suíte inteira
rodar. O que ele pode fazer é rodar testes a mais.

## `--map=<arquivo>`

O mapa fica fora do projeto, no diretório de trabalho do Sonda, e é refeito a cada rodada.
`--map=<arquivo>` o guarda em outro lugar, por exemplo dentro do projeto, para o cache do CI levá-lo
de um job ao outro. O arquivo guarda caminhos relativos ao projeto, então serve a um checkout em
outro diretório, e ele próprio não conta como mudança:

```yaml
coverage:
  cache:
    key: sonda-test-map
    paths: [.sonda/tests.map]
  script:
    - vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

## `--covered-by`

```bash
sonda test --html=coverage/html --covered-by
```

Cada linha e branch cobertos levam os nomes dos testes que os executaram, como no Xdebug; no HTML,
aparecem ao passar o mouse na linha, e um teste que falhou aparece em vermelho. Pesa numa suíte
grande: com 5.615 testes, o HTML foi de 123 MB para 1,3 GB e a memória do relatório de 300 MB para
870 MB.

Tanto `--affected` quanto `--covered-by` dependem de o Sonda ler o arquivo e o tamanho de cada
teste. Quando ele não consegue para algum teste, a cobertura é a mesma, mas o comando avisa que esses
dois podem sair incompletos:

```
Warning: Sonda could not read the file or the size of some test; the coverage is the same, but --affected and --covered-by may come out incomplete: <problem>
```
