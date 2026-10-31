---
title: GitLab
sidebar_position: 11
description: Um job de merge request que mede só o que o MR mudou, marca o diff e exige 100%.
---

O job abaixo é o que um projeto Laravel real roda em cada merge request. Ele mede só os arquivos
que o MR tocou, exige 100% deles e entrega ao GitLab dois relatórios que marcam o diff do MR.

## O job `coverage`

```yaml
coverage:
  extends: test      # the job that installs dependencies, prepares the database and runs the suite
  retry: 0
  script:
    - |
      HEAD_SHA="${CI_MERGE_REQUEST_SOURCE_BRANCH_SHA:-$CI_COMMIT_SHA}"
      git fetch --quiet --depth=1 origin "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA"
      # With GIT_DEPTH 1 there is no merge-base: Sonda uses the MR's own base sha as the base of the diff.
      php vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" \
        --min-lines=99 --min-branches=84 \
        --html=coverage/html --cobertura=coverage/cobertura.xml \
        --code-quality=coverage/code-quality.json --processes=3
  artifacts:
    when: always
    expire_in: 1 week
    paths:
      - coverage/html/
    reports:
      coverage_report:
        coverage_format: cobertura
        path: coverage/cobertura.xml
      codequality: coverage/code-quality.json
  only:
    - merge_requests
```

O que cada peça faz:

- **`extends: test`** reaproveita o job de teste do projeto: dependências do cache, banco
  preparado, `APP_ENV=testing`. O Sonda está no `vendor/` como dependência de dev
  (`composer require --dev ddr/sonda`), então nada é instalado no runner.
- **`GIT_DEPTH: 1` e os dois shas.** Com clone raso não há história em comum para achar o
  `merge-base`, então o job busca os commits de base e de topo do MR com `--depth=1` e passa o
  próprio sha da base ao `--changed`: o Sonda faz o diff contra esse commit. É para isso que serve
  o `CI_MERGE_REQUEST_DIFF_BASE_SHA`, e é por isso que o job roda `only: merge_requests`.
- **`--changed`** mede só os arquivos do `<source>` que o MR mudou e reprova o job se uma linha
  alterada ou um método tocado ficar descoberto; arquivo novo é cobrado inteiro. Veja
  [Só o que mudou](../usage/only-what-changed.md).
- **`--min-lines` e `--min-branches`** são um piso para o projeto inteiro, além dos 100% do que
  mudou. Com eles, o Sonda mede o `<source>` inteiro, então o total impresso é o do projeto. Ponha
  onde o projeto está e suba conforme a cobertura crescer; o piso de branches é mais baixo neste
  projeto porque as views do Blade dele ainda têm a maior parte dos branches sem teste.
- **`--cobertura`** é o que o GitLab lê para marcar as linhas cobertas e descobertas no diff do MR
  (`coverage_report`).
- **`--code-quality`** transforma cada trecho de linhas descoberto e cada branch não tomado do que
  o veredito cobra num apontamento de Code Quality, mostrado na própria linha no diff do MR
  (`codequality`).
- **`--html`** fica como artefato navegável por uma semana; `when: always` guarda os relatórios
  quando o job falha, que é quando eles importam.
- **`--processes=3`** vai para o ParaTest; escolha pelo runner.

A saída do veredito no log do job fica assim quando sobra algo:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

## Mais rápido: só os testes afetados

`--affected` roda só os arquivos de teste que executam o que mudou, por um mapa que cada rodada
grava. Guarde o mapa no cache do CI com `--map` para ele passar de um job ao outro:

```yaml
coverage:
  cache:
    key: sonda-test-map
    paths: [.sonda/tests.map]
  script:
    - vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

O `--affected` é deixado de lado quando há piso (`--min-lines`, `--min-branches`), porque só a
suíte inteira responde pelo projeto inteiro. Veja
[Só os testes afetados](../usage/affected-tests.md).

## Companheiro: mutation testing do que mudou

Cobertura diz que uma linha rodou; não diz que um teste conferiu o comportamento dela. O mesmo
projeto roda um job `mutation` ao lado do `coverage`, com o plugin de mutação do Pest nos arquivos
que o MR mudou:

```yaml
mutation:
  extends: test
  retry: 0
  allow_failure: true
  script:
    - |
      HEAD_SHA="${CI_MERGE_REQUEST_SOURCE_BRANCH_SHA:-$CI_COMMIT_SHA}"
      git fetch --quiet --depth=1 origin "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA"
      FILES=$(git diff --name-only --diff-filter=AMR "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA" -- app \
        | grep -E '\.php$' | paste -sd, -) || true
      if [ -z "$FILES" ]; then
        echo "No app/ file changed: nothing to mutate."
        exit 0
      fi
      php vendor/bin/pest --mutate --parallel --no-cache --covered-only \
        --path="$FILES" --min=100 --ignore-min-score-on-zero-mutations
  only:
    - merge_requests
```

Mutation testing precisa de um driver de cobertura no runner (o PCOV basta) para mapear testes a
linhas; um mutante que sobrevive é um teste que executa a linha sem conferir o que ela faz, enquanto
linha descoberta é assunto do job `coverage`. `allow_failure: true` deixa o job se calibrar em MRs
reais antes de bloqueá-los. Veja a
[documentação do Pest sobre mutation testing](https://pestphp.com/docs/mutation-testing).
