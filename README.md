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

O Xdebug com paths na suíte inteira só terminou dividido em lotes de ~80 testes, cada um num
processo novo: o php-code-coverage acumula, a cada teste, os ids de teste em todos os branches e
caminhos dos arquivos carregados, e o custo por teste cresce ao longo da execução (de 56 para 3
testes por minuto com 6 workers). Em lotes, 48 processos em 6 filas, 2h24; direto, não cabia numa
noite.

Com `--parallel` (10 workers): 42s sem cobertura, 40s com o Sonda.

O custo do Xdebug com paths é do próprio Xdebug, em C, e é por teste: num arquivo de 71 testes dessa
suíte, 8s com o Xdebug carregado e a cobertura desligada, 257s com `XDEBUG_CC_BRANCH_CHECK` ligado
uma vez no bootstrap, sem o php-code-coverage processar nada. Restringir os arquivos medidos não
muda esse número.

Fidelidade contra o Xdebug, medida na suíte inteira dessa aplicação (4.923 testes, 697 arquivos de
`app/`, Xdebug com paths rodado em lotes de testes, 2h24 num Mac mini M1): 12.1 mil linhas
executáveis, nenhuma divergência de linha coberta ou não coberta; 5 linhas em que só uma das
ferramentas vê um ponto executável, todas em instrução de várias linhas (heredoc, `match` de
retorno, fechamento de closure). Vereditos de branch por método: 2.333 iguais; 21 em que só o
Xdebug dá 100%, todos pelo modo estrito do Sonda; 42 em que só o Sonda dá 100%, com 108 blocos
que o Xdebug dá como perdidos e que têm todas as linhas executadas pela própria contagem dele:
desvio de fallback de função frameless, corpo de `if` terminado em `return`/`throw`, braço
implícito do `match` e blocos duplicados do `&&`/`||`. Nenhum trecho sem execução ficou escondido
atrás de um 100% do Sonda.

## Requisitos

- PHP 8.4 ou mais recente, com `git` no PATH para `--changed`.
- No projeto medido: PHPUnit 12 (Pest 4) e php-code-coverage 12, que trazem o `nikic/php-parser`
  usado pela instrumentação. O Sonda usa a fachada de eventos do PHPUnit, que é interna, e por
  isso aceita só essa versão.

## Instalação

Nada é instalado no projeto medido. Um clone por máquina, uma vez, e o comando no PATH:

```bash
git clone https://github.com/danie1net0/sonda.git /opt/sonda
ln -s /opt/sonda/bin/sonda /usr/local/bin/sonda
```

Atualizar é `git -C /opt/sonda pull`.

## Uso

Na raiz do projeto:

```bash
sonda test
```

O Sonda lê o `<source>` (`include` e `exclude`) e o `bootstrap` do `phpunit.xml`, roda os testes
com `php artisan test --parallel` (ou `vendor/bin/pest`, `vendor/bin/paratest`,
`vendor/bin/phpunit`, o que houver), e mostra:

```
Cobertura: linhas 11140/11232 (99,18%), branches 4303/4371 (98,44%)
```

Qualquer argumento que o Sonda não conheça vai para o runner: `sonda test --filter=Order`,
`sonda test tests/Feature/Actions`.

### Só o que mudou

```bash
sonda test --changed=origin/master
```

Mede só os arquivos do `<source>` alterados desde o `merge-base` com a referência: commitados,
modificados e novos. Num clone raso, sem história em comum para achar o `merge-base`, a própria
referência serve de base, que é o que o CI entrega (`CI_MERGE_REQUEST_DIFF_BASE_SHA` no GitLab).
O veredito cobra 100% **do que mudou**: as linhas que o diff acrescentou ou trocou e os métodos
e funções em que elas caem, inteiros; arquivo novo é cobrado inteiro. Lacuna antiga noutro método
do mesmo arquivo não reprova. Lista o que sobrou descoberto e sai com erro:

```
Cobertura: linhas 118/120 (98,33%), branches 61/63 (96,83%)
Do que mudou: linhas 40/42 (95,24%), branches 18/20 (90,00%)
Sem cobertura:
app/Actions/Scheduling/BookAppointmentAction.php: linhas 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

É o uso pensado para o CI: um job que mede só o que o MR tocou e exige 100%. `--files=a.php,b.php`
cobra os arquivos da lista inteiros, mesmo que também tenham mudado.

### Opções

| Opção | Efeito |
|---|---|
| `--changed=<ref>` | mede só os arquivos alterados desde o merge-base com a referência e falha se as linhas mudadas ou os métodos tocados tiverem algo descoberto |
| `--files=a,b` | mede só esses arquivos e falha se sobrar algo descoberto neles, inteiros |
| `--html=<dir>` | grava o relatório HTML |
| `--clover=<arq>` | grava o relatório Clover |
| `--cov=<arq>` | grava o `.cov` do php-code-coverage, que serve para qualquer outro formato |
| `--cobertura=<arq>` | grava o relatório Cobertura, o formato que o GitLab lê para marcar a cobertura no diff do MR |
| `--no-strict` | não exige o `else` de todo `if` nem o lado nulo de `?->` |
| `--no-fast` | sondas sem a referência local por função |
| `--no-parallel` | não usa o ParaTest mesmo quando instalado |
| `-c <arquivo>` | o `phpunit.xml` a usar, também repassado ao runner |

Cache e execuções ficam fora do projeto, em `$XDG_CACHE_HOME/sonda` ou `~/.cache/sonda`, num
diretório privado do usuário (modo 0700): as cópias instrumentadas rodam como código, então
ninguém mais pode escrever ali. O Sonda recusa um diretório de cache que pertença a outro usuário
ou esteja aberto a outros.

### Sem o comando

O `bootstrap.php` funciona direto no PHPUnit, no Pest e no ParaTest, configurado por variáveis
de ambiente; é o que o comando faz por baixo:

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_EXCLUDE=app/Providers \
SONDA_CACHE=/tmp/sonda/cache SONDA_OUT=/tmp/sonda/runs SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=/opt/sonda/bootstrap.php

php /opt/sonda/report.php /tmp/sonda/runs app coverage.cov app/Providers
```

| Variável | Obrigatória | Significado |
|---|---|---|
| `SONDA_AUTOLOAD` | sim | `vendor/autoload.php` do projeto medido |
| `SONDA_TARGET` | sim | diretórios instrumentados, separados por `PATH_SEPARATOR` |
| `SONDA_CACHE` | sim | diretório dos arquivos instrumentados |
| `SONDA_OUT` | sim | diretório das execuções, um arquivo por processo; use um novo a cada medição |
| `SONDA_EXCLUDE` | não | diretórios deixados de fora |
| `SONDA_ONLY` | não | os únicos arquivos medidos |
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
4. No fim de cada processo, a execução é gravada. O comando junta as execuções de todos os
   workers e monta o relatório com o php-code-coverage.

## Semântica dos branches

Um branch é um desfecho de decisão: a entrada de uma função, o corpo de `if`, `elseif`, `else`,
laço, `case`, `catch` e `finally`, o operando direito de `&&`, `||`, `and`, `or` e `??`, os
braços de `?:` e `match`, e o valor não nulo de `?->`.

No modo estrito, que é o padrão:

- Todo `if` sem `else` ganha um `else` sintético: os dois desfechos precisam ser testados. O
  Xdebug conta blocos básicos, então um `if` sem `else` cuja condição é sempre verdadeira dá
  100% nele e não no Sonda.
- `?->` conta os dois lados, nulo e não nulo.
- O operando esquerdo de `&&`, `||`, `and` e `or` precisa decidir sozinho ao menos uma vez (o
  curto-circuito, quando o direito nem roda), como o Xdebug conta. Em `??` não: o lado esquerdo
  pode ser uma chave indefinida, que só o próprio operador lê sem aviso.

O Sonda não conta o branch implícito de "nenhum braço casou" do `match`, que o Xdebug conta e
nenhum teste alcança quando os braços cobrem todos os casos de um enum.

O Sonda também não conta o desvio de fallback que o Xdebug vê em chamada a função nativa
*frameless* (`in_array`, `max`, `preg_replace`, `str_contains`...) feita dentro de namespace sem
`use function`: é um detalhe do opcode, não uma decisão do código, e um `use function` só para
calar a cobertura deixa de ser necessário.

Um método chamado como *first-class callable* (`$this->metodo(...)`) aparece no Xdebug como uma
função solta com o nome do método, com os acertos, e o método da classe fica zerado; o Sonda
credita o método. O `tools/compare.php` junta as duas entradas antes de comparar.

## Limitações conhecidas

- Corpo vazio de `if` e de laço não ganha sonda.
- `if` em sintaxe alternativa (`endif`) não ganha o `else` sintético.
- Property hook com corpo de expressão (`set => ...`) e arrow function por referência (`fn&`)
  não são instrumentados.
- Views do Blade e do Livewire não são medidas.
- Um arquivo do diretório alvo carregado por `require` direto ou pela chave `files` do Composer
  não é instrumentado e aparece inteiro como não coberto.
- O ParaTest aceita um único caminho de teste: `sonda test a.php b.php` roda sem ele, em série.
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
tools/compare-with-xdebug.sh <pasta de saída>   # na raiz de um projeto: Sonda, Xdebug com paths e a comparação, com summary.txt
```

O `compare-with-xdebug.sh` roda a suíte duas vezes, com o Sonda e com o Xdebug em `--parallel`, e
deixa `sonda.cov`, `xdebug.cov`, `compare.txt` e `summary.txt` na pasta informada; argumentos extras
vão para o runner (`tools/compare-with-xdebug.sh saida tests/Feature/Pedidos` mede só essa pasta).
Ele gera um `phpunit.xml` próprio com `pathCoverage="true"`, porque o ParaTest não aceita
`--path-coverage`, e com caminhos absolutos. O `--coverage-filter` do PHPUnit não serve para
restringir: ele soma ao `<source>` do `phpunit.xml`. Em suíte grande, `BATCH=80` roda o lado
Xdebug em lotes de até 80 testes, um processo por lote e `PROCESSES` filas com banco próprio
(`${DB_DATABASE}_q<N>`), e junta os `.cov` no fim: foi assim que a suíte do Laravel acima terminou.

Todo código novo sai com 100% de linhas e de branches, medidos com Xdebug, e com mutação sem
sobreviventes além dos equivalentes. Os testes de `tests/Feature` rodam o `bin/sonda` e o
`bootstrap.php` sobre um projeto de exemplo, com PHPUnit e com ParaTest.

## Licença

MIT.
