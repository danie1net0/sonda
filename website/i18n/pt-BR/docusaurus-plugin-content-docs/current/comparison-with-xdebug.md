---
title: Comparação com o Xdebug
sidebar_position: 10
description: Tempo e memória contra o Xdebug e o PCOV, fidelidade numa suíte inteira e como reproduzir a comparação.
---

Os números do Sonda foram validados contra o path coverage do Xdebug numa suíte real inteira. Esta
página tem as medições e o comando que reproduz a comparação no seu projeto.

## Tempo e memória

A suíte de um projeto Laravel 13 com Filament, Pest 4 e Postgres, 4.514 testes:

| Rodada | Tempo | Memória |
|---|---|---|
| Sem cobertura | 178s | 308 MB |
| Sonda | 174s | 317 MB |
| Xdebug, só linhas | 671s | 640 MB |
| Xdebug com `--path-coverage` | não terminou em 63 min | mais de 760 MB |

O Xdebug com paths só terminou a suíte inteira dividida em lotes de ~80 testes, cada um num processo
novo: o php-code-coverage acumula, a cada teste, os ids de teste em todos os branches e caminhos dos
arquivos carregados, e o custo por teste cresce ao longo da rodada (de 56 para 3 testes por minuto
com 6 workers). Em lotes, 48 processos em 6 filas, 2h24; de uma vez, não coube numa noite.

Com `--parallel` (10 workers): 42s sem cobertura, 40s com o Sonda.
Pico de memória por worker, nos 10 workers de `tests/Feature/Filament` dessa suíte, com o wrapper
de include ligado: 52 MB. Até a 1.14.2, esse wrapper prendia memória a cada operação de arquivo, e o
pico chegava a 107 MB por worker.

O custo do Xdebug com paths é do próprio Xdebug, em C, e é por teste: num arquivo de 71 testes dessa
suíte, 8s com o Xdebug carregado e a cobertura desligada, 257s com `XDEBUG_CC_BRANCH_CHECK` ligado
uma vez no bootstrap, sem o php-code-coverage processar nada. Restringir os arquivos medidos não
muda esse número.

## Fidelidade

Medida na suíte inteira dessa aplicação (4.923 testes, 697 arquivos em `app/`, Xdebug com paths
rodado em lotes de testes, 2h24 num Mac mini M1):

- 12,1 mil linhas executáveis, nenhuma divergência sobre quais linhas estão cobertas ou não; 5
  linhas em que só uma das ferramentas vê um ponto executável, todas em comandos de várias linhas
  (heredoc, `match` devolvido, fechamento de uma closure).
- Vereditos de branch por método: 2.333 iguais; 21 em que só o Xdebug dá 100%, todos por causa do
  modo estrito do Sonda; 42 em que só o Sonda dá 100%, com 108 blocos que o Xdebug dá como perdidos
  enquanto todas as linhas deles são executadas pela própria contagem dele: o desvio de fallback de
  uma função frameless, o corpo de um `if` que termina em `return`/`throw`, o braço implícito do
  `match` e blocos duplicados de `&&`/`||`.
- Nenhum código não executado ficou escondido atrás de um 100% do Sonda.

As regras por trás das diferenças estão em [Semântica dos branches](branch-semantics.md).

## Reproduzindo: `sonda compare`

```bash
sonda compare <diretório de saída>
```

Na raiz de um projeto, `sonda compare` roda a suíte duas vezes, com o Sonda e com o Xdebug em
`--parallel`, e deixa `sonda.cov`, `xdebug.cov`, `compare.txt` e `summary.txt` no diretório dado.
Precisa de um banco privado em `DB_DATABASE` no ambiente e de uma máquina livre. Argumentos extras
vão para o runner: `sonda compare output tests/Feature/Orders` mede só essa pasta.

| Opção | Significado |
|---|---|
| `--processes=<n>` | workers do ParaTest, ou filas de lotes (padrão 3) |
| `--batch=<n>` | testes por lote do lado Xdebug, que numa suíte grande não termina de uma vez |
| `--xdebug=<so>` | o `xdebug.so` a carregar |

Ele gera o próprio `phpunit.xml` com `pathCoverage="true"`, porque o ParaTest não aceita
`--path-coverage`, e com caminhos absolutos. O `--coverage-filter` do PHPUnit não restringe nada:
ele acrescenta ao `<source>` do `phpunit.xml`. Numa suíte grande, `--batch=80` roda o lado Xdebug em
lotes de até 80 testes, um processo por lote e `--processes` filas com o próprio banco
(`${DB_DATABASE}_q<N>`), e junta os arquivos `.cov` no fim: foi assim que a suíte Laravel acima
terminou.

O comando empacota o `tools/compare-with-xdebug.sh` (`--processes`, `--batch` e `--xdebug` viram as
variáveis `PROCESSES`, `BATCH` e `XDEBUG_SO`). `php tools/compare.php xdebug.cov sonda.cov -v`
compara dois arquivos `.cov` linha a linha e método a método, fundindo antes as entradas de
first-class callable do Xdebug nos métodos delas.
