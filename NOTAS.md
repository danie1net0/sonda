# covproto — notas do protótipo

Protótipo de cobertura de linhas e branches para PHP por instrumentação do código-fonte
(como o Istanbul do JavaScript), feito para substituir o Xdebug com `--path-coverage` no CI.

## Como funciona

- `Instrumenter` lê o arquivo com `nikic/php-parser`; o `InstrumentingVisitor` decide onde vão
  as sondas (`\CovProto\Hits::$current['<arquivo>'][<id>]=1`) e o `SourceEditor` as insere por
  posição de byte no fonte original, sem reimprimir o código. Nenhuma linha muda de número.
- `bootstrap.php` registra o `InstrumentingAutoloader` na frente do Composer: as classes do
  diretório alvo são carregadas da cópia instrumentada, guardada pelo `InstrumentationCache`
  (chave: caminho, data de modificação e impressão digital do instrumentador).
- `PhpUnit\Extension` só conta as sondas executadas durante cada teste (a mesma janela que o
  PHPUnit dá ao Xdebug), então código executado em data provider não conta.
- No fim do processo, o bootstrap grava a execução (`Run`). `report.php` usa o
  `Report\ReportWriter` para convertê-la num `.cov` do php-code-coverage, que serve para os
  relatórios HTML, Clover etc.
- `tools/` tem os scripts de validação usados nas medições: `compare.php` (Xdebug contra
  covproto, linha a linha e método a método), `inspect.php`, `lint-corpus.php` e os de benchmark.

Variáveis de ambiente do bootstrap (`Settings`): `COV_AUTOLOAD` (autoload do projeto),
`COV_TARGET` (diretório instrumentado, ex.: `app`), `COV_CACHE`, `COV_OUT` (arquivo da
execução), `COV_STRICT=1` (semântica estrita), `COV_FAST=1` (referência local por função,
sondas mais baratas), `COV_CHAIN` (bootstrap original do projeto, quando não é só o autoload).

Requer PHPUnit 12 (Pest 4) e php-code-coverage 12, vindos do projeto medido. Outras versões
mudam a API de extensão e o formato do `.cov` (o php-code-coverage 14 usa `Serializer`).

## Uso (Pest 4 ou PHPUnit 12)

```bash
COV_AUTOLOAD=vendor/autoload.php COV_TARGET=app COV_CACHE=/tmp/covcache COV_OUT=/tmp/run.ser \
COV_STRICT=1 COV_FAST=1 \
vendor/bin/pest <testes> --no-coverage \
  --bootstrap /Volumes/Projects/php/covproto/bootstrap.php \
  --extension 'CovProto\PhpUnit\Extension'

php -d memory_limit=-1 /Volumes/Projects/php/covproto/report.php /tmp/run.ser app /tmp/covproto.cov app/Providers,app/Console/Commands
php -d memory_limit=-1 /Volumes/Projects/php/covproto/tools/compare.php /tmp/xdebug.cov /tmp/covproto.cov -v
```

Os scripts rodam a partir da raiz do projeto medido (usam o `vendor/` dele).

## Testes do próprio covproto

```bash
vendor/bin/pest
XDEBUG_MODE=coverage php -d zend_extension=/opt/homebrew/opt/xdebug@8.5/xdebug.so vendor/bin/pest --path-coverage --coverage-text
XDEBUG_MODE=coverage php -d zend_extension=/opt/homebrew/opt/xdebug@8.5/xdebug.so vendor/bin/pest --mutate --class='CovProto\Probe'
```

Estado em 13/10/2026: 122 testes, 100% de linhas (350/350) e de branches (352/352) em `src/`
medidos com Xdebug. Mutação (`--mutate` classe a classe): 100% em todas as classes, salvo dois
mutantes equivalentes no visitor (valor inicial de `visitIndex`, do qual só a ordem relativa
importa) e um no valor padrão de `Hits::$perTest`, que o Pest não executa por estar numa linha
de declaração (o teste por reflection cobre esse valor). `--mutate --parallel --everything` não
ativa a mutação no Pest 4: rodar por classe.

`tools/lint-corpus.php <diretório> 1` instrumenta todos os arquivos PHP de um diretório em modo
estrito e confere que o resultado é PHP válido, com as mesmas linhas e sem sonda caindo em HTML
(templates com `?>`/`<?=`). Último resultado: 2.949 arquivos do `vendor/` do covproto, sem erros.

`tests/Feature/EndToEndTest.php` roda um projeto de exemplo (`tests/Fixtures/Project`) num
subprocesso do PHPUnit com o bootstrap e a extensão, gera o relatório e confere linhas e
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

## Pendências conhecidas

- Corpo vazio de `if` e de laço não ganha sonda; operador `?->`; `else` sintético na sintaxe
  alternativa (`endif`).
- Views do Blade e Livewire não são medidas.
- Testes de arquitetura que leem o arquivo da classe por reflection veem o arquivo instrumentado.
- `COV_FAST` cria a variável local `$__cov`, visível em `get_defined_vars()`.
- Integração com `--parallel` (paratest) ainda não existe: cada worker grava o mesmo `COV_OUT`.
- Corpo de property hook em forma de expressão (`set => ...`) não é instrumentado.
- `tools/bench.sh` e `tools/run.sh` são os scripts de medição usados, com caminhos das suítes
  de teste (CommonMark e brick/math clonados à parte).
