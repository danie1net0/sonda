---
title: Referência da CLI
sidebar_position: 5
description: Todas as opções do sonda test e do sonda compare.
---

`sonda test` mede o projeto do diretório atual; `sonda compare` o mede com o Sonda e com o Xdebug e
compara os dois. `sonda --version` (ou `-V`) mostra a versão do sonda que está rodando; `sonda help`
imprime esta referência.

## `sonda test [opções] [argumentos do runner]`

Roda os testes do projeto do diretório atual com a instrumentação do Sonda e imprime a cobertura de
linhas e de branches do `<source>` do `phpunit.xml`. Nada é instalado no projeto.

### Escopo e veredito

| Opção | Significado |
|---|---|
| `--changed=<ref>` | mede só os arquivos do `<source>` alterados desde o merge-base com a referência (commitados, modificados e novos) e reprova se as linhas alteradas ou os métodos tocados tiverem algo descoberto. Veja [Só o que mudou](only-what-changed.md) |
| `--files=a,b` | mede só esses arquivos e reprova se sobrar algo descoberto neles, inteiros |
| `--affected` | com `--changed` ou `--files`, roda só os testes que executam o que mudou, pelo mapa que cada rodada grava; sem mapa, ou com mudança que ele não explica (config, rotas, arquivo novo), roda a suíte inteira. Veja [Só os testes afetados](affected-tests.md) |
| `--map=<arquivo>` | onde guardar o mapa de testes do `--affected`, para o cache do CI |
| `--min-lines=<n>` | reprova se a cobertura de linhas do projeto inteiro ficar abaixo de n% (piso global, além dos 100% do que mudou). Com piso, o Sonda instrumenta e mede o `<source>` inteiro mesmo com `--changed` ou `--files`, cujo veredito continua olhando só os arquivos deles; o total impresso é o do projeto |
| `--min-branches=<n>` | o mesmo para branches |

### Relatórios

| Opção | Significado |
|---|---|
| `--html=<dir>` | grava o relatório HTML |
| `--clover=<arquivo>` | grava o relatório Clover |
| `--cov=<arquivo>` | grava o `.cov` do php-code-coverage, que serve a qualquer outro formato |
| `--cobertura=<arquivo>` | grava o relatório Cobertura, o formato que o GitLab lê para marcar a cobertura no diff do MR |
| `--code-quality=<arquivo>` | grava o que ficou descoberto como relatório de Code Quality do GitLab: cada trecho de linhas e cada branch sem teste vira um apontamento no diff do MR. Com `--changed` ou `--files`, só o que o veredito cobra; sem eles, o projeto inteiro. Os caminhos partem da raiz do repositório |
| `--text` | lista a cobertura de cada arquivo depois do total |
| `--covered-by` | cada linha e branch cobertos levam os nomes dos testes que os executaram, como no Xdebug; no HTML, aparecem ao passar o mouse na linha. Pesa numa suíte grande: com 5.615 testes, o HTML foi de 123 MB para 1,3 GB e a memória do relatório de 300 MB para 870 MB |

### O que é medido e como

| Opção | Significado |
|---|---|
| `--no-strict` | não exige o `else` de todo `if` nem o lado nulo de `?->`. Veja [Semântica dos branches](../branch-semantics.md) |
| `--no-fast` | sondas sem a referência local por função (uma atribuição por sonda é o padrão) |
| `--no-parallel` | não usa o ParaTest mesmo quando instalado |
| `--no-blade` | não mede as views do Blade (`resources/views`) de um projeto Laravel |
| `--views=a,b` | mede também as views do Blade dessas pastas. Veja [Views do Blade](../blade-views.md) |
| `--includes` | mede também os arquivos do diretório alvo carregados por `include` ou `require` direto (padrão: só quando o source tem arquivos sem classe, como migrations) |
| `--no-includes` | não mede esses arquivos |
| `-c <arquivo>` | o `phpunit.xml` a usar, também repassado ao runner |

### Argumentos do runner

Qualquer outro argumento vai para o runner (`php artisan test`, `pest`, `paratest` ou `phpunit`, o
que o projeto tiver): `sonda test --filter=Order`, `sonda test tests/Feature/Actions`,
`sonda test --processes=3`. Vários caminhos (`sonda test tests/Unit tests/Feature/Actions`) rodam em
paralelo do mesmo jeito: o ParaTest só aceita um na linha de comando, então o Sonda os põe num
`phpunit.xml` gerado ao lado do do projeto, apagado no fim.

## `sonda compare <saída> [opções] [argumentos do runner]`

Mede o projeto com o Sonda e com o Xdebug (path coverage) e compara os dois, gravando `sonda.cov`,
`xdebug.cov`, `compare.txt` e `summary.txt` em `<saída>`. Precisa de um banco privado em
`DB_DATABASE` no ambiente e de uma máquina livre. Veja
[Comparação com o Xdebug](../comparison-with-xdebug.md) para o que ele faz e como ficam os resultados.

| Opção | Significado |
|---|---|
| `--processes=<n>` | workers do ParaTest, ou filas de lotes (padrão 3) |
| `--batch=<n>` | testes por lote do lado Xdebug, que numa suíte grande não termina de uma vez |
| `--xdebug=<so>` | o `xdebug.so` a carregar |

Argumentos extras vão para o runner: `sonda compare output tests/Feature/Orders` mede só essa pasta.

## `sonda --version`

Imprime `sonda <versão>`: a versão que o Composer instalou (`sonda 1.16.0`) ou, num clone, a tag
mais próxima e os commits depois dela (`sonda 1.16.0-2-g8e8a744`).
