---
title: Sem o comando
sidebar_position: 6
description: Use o bootstrap direto no PHPUnit, Pest ou ParaTest, configurado por variáveis de ambiente.
---

O `bootstrap.php` funciona direto no PHPUnit, no Pest e no ParaTest, configurado por variáveis de
ambiente; é o que o comando faz por baixo. Serve a um runner que o comando não conhece, ou a um
passo de CI que quer conduzir as peças por conta própria.

## O bootstrap

Passe o bootstrap ao runner com as variáveis definidas. Com o Sonda instalado pelo Composer:

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_EXCLUDE=app/Providers \
SONDA_CACHE=/tmp/sonda/cache SONDA_OUT=/tmp/sonda/runs SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=vendor/ddr/sonda/bootstrap.php
```

Com um clone, o bootstrap é `/opt/sonda/bootstrap.php`. Cada processo (cada worker do ParaTest
incluído) grava a própria execução em `SONDA_OUT`; use um diretório novo para cada medição.

### Variáveis

Listas são separadas por `PATH_SEPARATOR` (`:` no Linux e no macOS).

| Variável | Obrigatória | Significado |
|---|---|---|
| `SONDA_AUTOLOAD` | sim | `vendor/autoload.php` do projeto medido |
| `SONDA_TARGET` | sim | diretórios instrumentados |
| `SONDA_CACHE` | sim | diretório dos arquivos instrumentados |
| `SONDA_OUT` | sim | diretório das execuções, um arquivo por processo; use um novo para cada medição |
| `SONDA_EXCLUDE` | não | diretórios deixados de fora |
| `SONDA_ONLY` | não | os únicos arquivos medidos |
| `SONDA_STRICT=1` | não | semântica estrita de branches |
| `SONDA_FAST=1` | não | referência local por função: sondas mais baratas |
| `SONDA_CHAIN` | não | o bootstrap original do projeto, quando não é só o autoload |
| `SONDA_VIEWS` | não | diretórios de views do Blade a medir |
| `SONDA_INCLUDES=1` | não | instrumenta também os arquivos do alvo carregados pelo caminho (`include`/`require`) |

## O relatório

O `report.php` junta as execuções de um diretório e grava um `.cov` do php-code-coverage, que serve
a qualquer outro formato:

```bash
php vendor/ddr/sonda/report.php /tmp/sonda/runs app coverage.cov app/Providers
```

Os argumentos: o diretório das execuções, o diretório do fonte, o arquivo `.cov` de saída e, se
quiser, diretórios excluídos.

```
usage: report.php <runs directory> <source directory> <output .cov file> [excluded directory]...
```
