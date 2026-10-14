# Sonda

Cobertura de linhas e de branches para PHP por instrumentação do código-fonte, muito mais
rápida e leve que o Xdebug com `--path-coverage`.

O Sonda insere sondas no código antes de carregá-lo, como o Istanbul faz no JavaScript. Cada
sonda é uma atribuição num array; não há interceptação de opcodes nem enumeração de caminhos,
que é o que torna o Xdebug lento e pesado.

## Medições

Suíte de um projeto Laravel 13 com Filament, Pest 4 e Postgres, 4.514 testes:

| Execução | Tempo | Memória |
|---|---|---|
| Sem cobertura | 178s | 308 MB |
| Sonda | 174s | 317 MB |
| Xdebug só linhas | 671s | 640 MB |
| Xdebug com `--path-coverage` | não terminou em 63 min | mais de 760 MB |

Com `--parallel` (10 workers): 42s sem cobertura, 40s com o Sonda.

Fidelidade contra o Xdebug nessa suíte: 11.229 linhas comparadas, nenhuma divergência de linha
coberta ou não coberta. Nos branches, o Sonda é mais rigoroso em dois pontos (ver semântica).

## Requisitos

- PHP 8.4 ou mais recente.
- No projeto medido: PHPUnit 12 (Pest 4) e php-code-coverage 12, que trazem o `nikic/php-parser`
  usado pela instrumentação. O Sonda usa a fachada de eventos do PHPUnit, que é interna, e por
  isso aceita só essa versão.

O Sonda não é instalado no projeto medido. Um clone por máquina basta:

```bash
git clone https://github.com/danie1net0/sonda.git /opt/sonda
```

## Uso

Na raiz do projeto medido, com ou sem `--parallel`:

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_CACHE=/tmp/sonda/cache SONDA_OUT=/tmp/sonda/runs \
SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=/opt/sonda/bootstrap.php

php /opt/sonda/report.php /tmp/sonda/runs app coverage.cov app/Providers app/Console/Commands
```

O `coverage.cov` é o formato do php-code-coverage: serve para gerar os relatórios HTML, Clover,
Cobertura e texto com as ferramentas de sempre.

Variáveis de ambiente lidas pelo bootstrap:

| Variável | Obrigatória | Significado |
|---|---|---|
| `SONDA_AUTOLOAD` | sim | `vendor/autoload.php` do projeto medido |
| `SONDA_TARGET` | sim | diretório instrumentado, por exemplo `app` ou `src` |
| `SONDA_CACHE` | sim | diretório dos arquivos instrumentados |
| `SONDA_OUT` | sim | diretório das execuções, um arquivo por processo; use um novo a cada medição |
| `SONDA_STRICT=1` | não | semântica estrita de branches |
| `SONDA_FAST=1` | não | referência local por função: sondas mais baratas |
| `SONDA_CHAIN` | não | bootstrap original do projeto, quando não é só o autoload |

## Como funciona

1. O bootstrap registra um autoloader na frente do Composer. Uma classe do diretório alvo é
   carregada de uma cópia instrumentada, guardada em cache por caminho, data de modificação e
   versão do instrumentador.
2. A instrumentação lê o arquivo com o `nikic/php-parser` e insere as sondas por posição de
   byte no fonte original, sem reimprimir o código. Nenhuma linha muda de número, e `__FILE__` e
   `__DIR__` continuam apontando para o arquivo original.
3. Só contam as sondas executadas durante cada teste, a mesma janela que o PHPUnit dá ao Xdebug:
   código executado em data provider não conta como coberto.
4. No fim de cada processo, a execução é gravada em `SONDA_OUT`. O `report.php` junta as
   execuções de todos os workers e gera o `.cov`.

## Semântica dos branches

Um branch é um desfecho de decisão: a entrada de uma função, o corpo de `if`, `elseif`, `else`,
laço, `case`, `catch` e `finally`, o operando direito de `&&`, `||`, `and`, `or` e `??`, os
braços de `?:` e `match`, e o valor não nulo de `?->`.

No modo estrito (`SONDA_STRICT=1`):

- Todo `if` sem `else` ganha um `else` sintético: os dois desfechos precisam ser testados. O
  Xdebug conta blocos básicos, então um `if` sem `else` cuja condição é sempre verdadeira dá
  100% nele e não no Sonda.
- `?->` conta os dois lados, nulo e não nulo.

O Sonda não conta o branch implícito de "nenhum braço casou" do `match`, que o Xdebug conta e
nenhum teste alcança quando os braços cobrem todos os casos de um enum.

## Limitações conhecidas

- Corpo vazio de `if` e de laço não ganha sonda.
- `if` em sintaxe alternativa (`endif`) não ganha o `else` sintético.
- Property hook com corpo de expressão (`set => ...`) e arrow function por referência (`fn&`)
  não são instrumentados.
- Views do Blade e do Livewire não são medidas.
- Um arquivo do diretório alvo carregado por `require` direto ou pela chave `files` do Composer
  não é instrumentado e aparece inteiro como não coberto.
- A linha de um `catch` pela qual passou uma exceção de outro tipo aparece como não coberta
  (o Xdebug a conta como executada).
- Testes que leem o arquivo da classe por reflection veem a cópia instrumentada.

## Desenvolvimento

```bash
composer check          # pint, phpstan, rector (dry-run) e a suíte
composer format         # rector e pint
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --path-coverage --coverage-text
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --mutate --class='Sonda\Probe'
php tools/lint-corpus.php vendor 1   # instrumenta um diretório inteiro e confere que o PHP continua válido
php tools/compare.php xdebug.cov sonda.cov -v   # compara dois .cov linha a linha e método a método
```

Todo código novo sai com 100% de linhas e de branches, medidos com Xdebug, e com mutação sem
sobreviventes além dos equivalentes. `tests/Feature/EndToEndTest.php` roda um projeto de exemplo
num subprocesso do PHPUnit e noutro do ParaTest.

## Licença

MIT.
