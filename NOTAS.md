# covproto — notas do protótipo

Protótipo de cobertura de linhas e branches para PHP por instrumentação do código-fonte
(como o Istanbul do JavaScript), feito para substituir o Xdebug com `--path-coverage` no CI.

## Como funciona

- `src/Instrumenter.php` lê o arquivo com `nikic/php-parser` e insere sondas
  (`\CovProto\R::$h['<arquivo>'][<id>]=1`) por posição de byte no fonte original, sem
  reimprimir o código. Nenhuma linha muda de número.
- `bootstrap.php` registra um autoloader na frente do Composer: as classes do diretório alvo
  são carregadas da versão instrumentada, guardada em cache por arquivo e data de modificação.
- `src/PhpUnitExtension.php` só conta as sondas executadas durante cada teste (a mesma janela
  que o PHPUnit dá ao Xdebug), então código executado em data provider não conta.
- `report.php` converte as sondas no formato do php-code-coverage e gera um `.cov`, que serve
  para os relatórios HTML, Clover etc.
- `compare.php` compara um `.cov` do Xdebug com um do protótipo, linha a linha e método a método.

Variáveis de ambiente do bootstrap: `COV_AUTOLOAD` (autoload do projeto), `COV_TARGET`
(diretório instrumentado, ex.: `app`), `COV_CACHE`, `COV_OUT` (arquivo de sondas),
`COV_STRICT=1` (semântica estrita), `COV_FAST=1` (referência local por função, sondas mais
baratas), `COV_CHAIN` (bootstrap original do projeto, quando não é só o autoload).

## Uso (Pest ou PHPUnit 10+)

```bash
COV_AUTOLOAD=vendor/autoload.php COV_TARGET=app COV_CACHE=/tmp/covcache COV_OUT=/tmp/hits.ser \
COV_STRICT=1 COV_FAST=1 \
vendor/bin/pest <testes> --no-coverage \
  --bootstrap /Volumes/Projects/php/covproto/bootstrap.php \
  --extension 'CovProto\PhpUnitExtension'

php -d memory_limit=-1 /Volumes/Projects/php/covproto/report.php /tmp/hits.ser app /tmp/proto.cov app/Providers,app/Console/Commands
php -d memory_limit=-1 /Volumes/Projects/php/covproto/compare.php /tmp/xdebug.cov /tmp/proto.cov -v
```

Os dois scripts PHP rodam a partir da raiz do projeto medido (usam o `vendor/` dele).

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

- Corpo vazio de `if`, laço e `catch` sem chaves; operador `?->`; `else` sintético na sintaxe
  alternativa (`endif`).
- Views do Blade e Livewire não são medidas.
- Testes de arquitetura que leem o arquivo da classe por reflection veem o arquivo instrumentado.
- `COV_FAST` cria a variável local `$__cov`, visível em `get_defined_vars()`.
- Testes do próprio protótipo e integração com `--parallel` ainda não existem.
- `tools/bench.sh` e `tools/run.sh` são os scripts de medição usados, com caminhos das suítes
  de teste (CommonMark e brick/math clonados à parte).
