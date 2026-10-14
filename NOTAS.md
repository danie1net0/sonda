# sonda — notas do protótipo

Protótipo de cobertura de linhas e branches para PHP por instrumentação do código-fonte
(como o Istanbul do JavaScript), feito para substituir o Xdebug com `--path-coverage` no CI.

## Como funciona

- `Instrumenter` lê o arquivo com `nikic/php-parser`; o `InstrumentingVisitor` decide onde vão
  as sondas (`\Sonda\Hits::$current['<arquivo>'][<id>]=1`) e o `SourceEditor` as insere por
  posição de byte no fonte original, sem reimprimir o código. Nenhuma linha muda de número.
- `bootstrap.php` registra o `InstrumentingAutoloader` na frente do Composer: as classes do
  diretório alvo são carregadas da cópia instrumentada, guardada pelo `InstrumentationCache`
  (chave: caminho, data de modificação e impressão digital do instrumentador).
- `PhpUnit\CollectionWindow`, aberta pelo próprio bootstrap, só conta as sondas executadas
  durante cada teste (a mesma janela que o PHPUnit dá ao Xdebug), então código executado em data
  provider não conta. Ela usa a fachada de eventos do PHPUnit, que é interna: por isso o sonda
  aceita só o PHPUnit 12. Não é extensão porque o ParaTest não repassa `--extension` aos workers.
- No fim do processo, o bootstrap grava a execução (`Run`) com nome próprio no diretório
  `SONDA_OUT`: com `--parallel`, cada worker grava a sua. `report.php` junta todas e usa o
  `Report\ReportWriter` para gerar um `.cov` do php-code-coverage, que serve para os relatórios
  HTML, Clover etc.
- `tools/` tem os scripts de validação usados nas medições: `compare.php` (Xdebug contra
  sonda, linha a linha e método a método), `inspect.php`, `lint-corpus.php` e os de benchmark.

Variáveis de ambiente do bootstrap (`Settings`): `SONDA_AUTOLOAD` (autoload do projeto),
`SONDA_TARGET` (diretório instrumentado, ex.: `app`), `SONDA_CACHE`, `SONDA_OUT` (diretório das
execuções, um arquivo por processo; usar um diretório novo a cada medição), `SONDA_STRICT=1` (semântica estrita), `SONDA_FAST=1` (referência local por função,
sondas mais baratas), `SONDA_CHAIN` (bootstrap original do projeto, quando não é só o autoload).

Requer PHPUnit 12 (Pest 4) e php-code-coverage 12, vindos do projeto medido. Outras versões
mudam a API de extensão e o formato do `.cov` (o php-code-coverage 14 usa `Serializer`).

## Uso (Pest 4 ou PHPUnit 12, com ou sem `--parallel`)

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_CACHE=/tmp/covcache SONDA_OUT=/tmp/covruns \
SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=/Volumes/Projects/php/sonda/bootstrap.php

php -d memory_limit=-1 /Volumes/Projects/php/sonda/report.php /tmp/covruns app /tmp/sonda.cov app/Providers,app/Console/Commands
php -d memory_limit=-1 /Volumes/Projects/php/sonda/tools/compare.php /tmp/xdebug.cov /tmp/sonda.cov -v
```

Os scripts rodam a partir da raiz do projeto medido (usam o `vendor/` dele).

## Testes do próprio sonda

```bash
vendor/bin/pest
XDEBUG_MODE=coverage php -d zend_extension=/opt/homebrew/opt/xdebug@8.5/xdebug.so vendor/bin/pest --path-coverage --coverage-text
XDEBUG_MODE=coverage php -d zend_extension=/opt/homebrew/opt/xdebug@8.5/xdebug.so vendor/bin/pest --mutate --class='Sonda\Probe'
```

Estado em 13/10/2026: 142 testes, 100% de linhas (399/399) e de branches (398/398) em `src/`
medidos com Xdebug. Mutação (`--mutate` classe a classe): 100% em todas as classes, salvo dois
mutantes equivalentes no visitor (valor inicial de `visitIndex`, do qual só a ordem relativa
importa) e um no valor padrão de `Hits::$perTest`, que o Pest não executa por estar numa linha
de declaração (o teste por reflection cobre esse valor). `--mutate --parallel --everything` não
ativa a mutação no Pest 4: rodar por classe.

`tools/lint-corpus.php <diretório> 1` instrumenta todos os arquivos PHP de um diretório em modo
estrito e confere que o resultado é PHP válido, com as mesmas linhas e sem sonda caindo em HTML
(templates com `?>`/`<?=`). Último resultado: 2.949 arquivos do `vendor/` do sonda, sem erros.

`tests/Feature/EndToEndTest.php` roda um projeto de exemplo (`tests/Fixtures/Project`) num
subprocesso do PHPUnit e num do ParaTest com 2 workers, gera o relatório e confere linhas e
branches, inclusive que código executado só no data provider não conta.

## Decisão de semântica (13/10/2026)

**Estrita.** Cada `if` precisa dos dois lados testados: o protótipo cria um `else` sintético
quando ele não existe. O Xdebug conta blocos básicos, então um `if` sem `else` com a condição
sempre verdadeira dá 100% nele. Em compensação, o protótipo não conta o branch implícito de
"nenhum braço casou" do `match`, que o Xdebug conta e nenhum teste alcança.

## Resultados medidos (12–13/10/2026, MacBook, PHP 8.5.8, Xdebug 3.5.3)

| Suíte | Sem cobertura | Protótipo | Xdebug só linhas | Xdebug com paths |
|---|---|---|---|---|
| CommonMark (4.406 testes) | 1,1s | 1,3s | 14,0s | não terminou em ~40 min (670 MB) |
| brick/math BigRationalTest (718) | 35–37s | 42–45s | 96s | 1.514s (com outra carga na máquina) |
| contata-ai `tests/Feature/Actions` (638) | 23,2s | 24,3s | 65,9s | não terminou em 63 min (764 MB) |
| contata-ai `Actions/Scheduling` (63) | 3,6s | 2,3s + 0,5s de relatório | — | 69,4s (730 MB) |

Memória do protótipo: a mesma da execução sem cobertura (90–140 MB).

Fidelidade contra o Xdebug:

- Linhas: nenhuma divergência de linha executada ou não executada nas quatro suítes, salvo a
  linha de um `catch` pela qual passou uma exceção de outro tipo (o Xdebug a marca; o
  protótipo não).
- Branches por método (100% ou não): no contata-ai/Scheduling, 702 de 705 concordam; as três
  diferenças são o `match` sobre enum e dois `if` sem `else` não testados (semântica estrita).
  No brick/math em modo estrito, 258 de 260; nenhum método dá 100% no protótipo e menos no Xdebug.
- Sintaxe: 5.898 arquivos de `vendor/` instrumentados geram PHP válido com as mesmas linhas.

## Validação na suíte inteira do contata-ai (13/10/2026)

4.514 testes (`vendor/bin/pest`, sem `--parallel`, banco `contata_cov`):

| Execução | Tempo | Memória | Resultado |
|---|---|---|---|
| Sem cobertura | 178s | 308 MB | 16 falhas (arquivos apagados no working tree, teste de data instável) |
| sonda, estrito e rápido | 174s | 317 MB | as mesmas 16 falhas, teste a teste, e o mesmo número de asserções |
| Xdebug só linhas | 671s | 640 MB | 17 falhas (o teste de data instável falhou também) |
| Sem cobertura, `pest --parallel` (10 workers) | 42s | — | 16 falhas |
| sonda, `pest --parallel` | 40s | — | as mesmas 16, teste a teste; 11 execuções juntadas (10 workers e o orquestrador) |
| sonda, `php artisan test --parallel` | 44s | — | 16 falhas |

Em paralelo, linhas e branches saem idênticos aos da execução sequencial. O orquestrador do
paralelo carrega o bootstrap depois de lacrar os eventos e não roda teste: a janela fica fechada
e nada do que ele executa conta.

Linhas contra o Xdebug: 11.229 linhas, nenhuma divergência de coberta ou não coberta. Sobram
5 diferenças de linha executável, todas inofensivas: o retorno implícito de uma closure que só o
Xdebug conta (a linha anterior já diz o mesmo) e 4 linhas que só o sonda conta, todas cobertas.

Essa validação achou três bugs, já corrigidos: o operando esquerdo de `??` em linha própria era
envolvido e deixava de silenciar chave indefinida (14 testes quebravam); classe do classmap com
arquivo apagado dava `TypeError` em vez do erro do Composer (o ArchTest passava escondendo a
falha); a linha do último braço de `match`, que o Xdebug sempre dá como executada, aparecia como
não coberta.

`?->`: o valor à esquerda passa por `Hits::nullsafe()`, que registra se era nulo ou não. No modo
estrito, os dois lados contam como branch. Dentro de `isset`, `empty` e à esquerda de `??`, o
valor ganha `?? null` para continuar silencioso.

## Pendências conhecidas

- Corpo vazio de `if` e de laço não ganha sonda; `else` sintético na sintaxe alternativa (`endif`).
- Views do Blade e Livewire não são medidas.
- Testes de arquitetura que leem o arquivo da classe por reflection veem o arquivo instrumentado.
- `SONDA_FAST` cria a variável local `$__sonda`, visível em `get_defined_vars()`.
- Corpo de property hook em forma de expressão (`set => ...`) não é instrumentado.
- `tools/bench.sh` e `tools/run.sh` são os scripts de medição usados, com caminhos das suítes
  de teste (CommonMark e brick/math clonados à parte).
