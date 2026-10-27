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
- No projeto medido: PHPUnit 11.5 (Pest 3) ou 12 (Pest 4), com o php-code-coverage da mesma
  versão, que trazem o `nikic/php-parser` usado pela instrumentação. O Sonda usa a fachada de
  eventos do PHPUnit, que é interna, e o CI roda a suíte nas duas versões, na mais baixa e na
  mais recente de cada.

## Instalação

Como dependência de desenvolvimento do projeto, que é o jeito de fixar a versão e deixar o
Renovate ou o Dependabot atualizá-la:

```bash
composer require --dev ddr/sonda
vendor/bin/sonda test
```

Sem instalar nada no projeto medido, um clone por máquina, uma vez, e o comando no PATH. O Sonda
usa então as dependências do próprio projeto (PHPUnit, php-code-coverage, php-parser):

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
`sonda test tests/Feature/Actions`. Vários caminhos (`sonda test tests/Unit tests/Feature/Actions`)
rodam em paralelo do mesmo jeito: o ParaTest só aceita um na linha de comando, então o Sonda os
põe num `phpunit.xml` gerado ao lado do do projeto, apagado no fim.

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

No GitLab, os relatórios Cobertura e Code Quality juntos marcam no diff do MR o que está coberto e
apontam, na própria linha, o que falta testar:

```yaml
coverage:
  script:
    - php .sonda/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --cobertura=coverage/cobertura.xml --code-quality=coverage/code-quality.json
  artifacts:
    when: always
    reports:
      coverage_report:
        coverage_format: cobertura
        path: coverage/cobertura.xml
      codequality: coverage/code-quality.json
```

### Só os testes afetados

```bash
sonda test --changed=origin/master --affected
```

Cada execução grava, fora do projeto, um mapa de que arquivos do `<source>` os testes de cada
arquivo de teste executaram. Com `--affected`, junto de `--changed` ou `--files`, o Sonda roda só
os arquivos de teste que executam o que mudou, mais os arquivos de teste que mudaram. Os testes
que cobrem o código alterado são todos esses, então o veredito continua o mesmo, em segundos em
vez da suíte inteira.

O mapa não responde por tudo, e aí a suíte inteira roda, medindo o projeto todo para o mapa
aprender: na primeira vez, quando muda um arquivo do `<source>` que nenhum teste conhecido
executa (arquivo novo, ou nunca executado), e quando muda qualquer arquivo que não é do `<source>`
nem um teste (configuração, rotas, migrations, `composer.lock`, um helper dos testes), porque
qualquer teste pode lê-lo. O comando diz o motivo antes de rodar. Com piso de cobertura, ou com
os testes a rodar já passados ao runner, `--affected` fica de lado.

O mapa fica fora do projeto, no diretório de trabalho do Sonda, e se atualiza a cada execução.
`--map=<arq>` o guarda noutro lugar, por exemplo dentro do projeto, para o cache do CI levá-lo de
um job para o outro. O arquivo guarda os caminhos relativos ao projeto, então serve a um checkout
noutro diretório, e ele mesmo não conta como mudança:

```yaml
coverage:
  cache:
    key: sonda-mapa-de-testes
    paths: [.sonda/tests.map]
  script:
    - php .sonda/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

Um mapa velho não deixa teste afetado de fora: cada execução reescreve o que os arquivos de teste
que rodaram executam, e um arquivo do `<source>` que o mapa não conhece manda rodar a suíte
inteira. O que ele pode fazer é rodar testes a mais. Com piso de cobertura (`--min-lines`,
`--min-branches`), a suíte inteira roda de qualquer jeito.

### Opções

| Opção | Efeito |
|---|---|
| `--changed=<ref>` | mede só os arquivos alterados desde o merge-base com a referência e falha se as linhas mudadas ou os métodos tocados tiverem algo descoberto |
| `--files=a,b` | mede só esses arquivos e falha se sobrar algo descoberto neles, inteiros |
| `--html=<dir>` | grava o relatório HTML |
| `--clover=<arq>` | grava o relatório Clover |
| `--cov=<arq>` | grava o `.cov` do php-code-coverage, que serve para qualquer outro formato |
| `--cobertura=<arq>` | grava o relatório Cobertura, o formato que o GitLab lê para marcar a cobertura no diff do MR |
| `--code-quality=<arq>` | grava o que ficou descoberto como relatório de Code Quality do GitLab: cada trecho de linhas e cada branch sem teste vira um apontamento no diff do MR. Com `--changed` ou `--files`, só o que o veredito cobra; sem eles, o projeto inteiro. Os caminhos partem da raiz do repositório |
| `--no-strict` | não exige o `else` de todo `if` nem o lado nulo de `?->` |
| `--no-fast` | sondas sem a referência local por função |
| `--no-parallel` | não usa o ParaTest mesmo quando instalado |
| `--no-blade` | não mede as views do Blade de um projeto Laravel |
| `--views=a,b` | mede também as views do Blade dessas pastas |
| `--text` | lista a cobertura de cada arquivo depois do total |
| `--affected` | com `--changed` ou `--files`, roda só os testes que executam o que mudou (ver acima) |
| `--includes`, `--no-includes` | instrumenta, ou não, os arquivos do diretório alvo carregados por `include` ou `require` direto; sem elas, o Sonda decide sozinho, ligando quando há arquivos que o autoloader não carrega |
| `--map=<arq>` | onde guardar o mapa de testes do `--affected`, para o cache do CI (ver acima) |
| `--covered-by` | cada linha e branch coberto leva o nome dos testes que o executaram, como com o Xdebug; no HTML, aparecem ao passar o mouse sobre a linha. Pesa numa suíte grande: em 5.615 testes, o HTML foi de 123 MB para 1,3 GB e a memória do relatório de 300 MB para 870 MB |
| `--min-lines=<n>`, `--min-branches=<n>` | falha se a cobertura do projeto inteiro ficar abaixo de n% (piso global, além do 100% do que mudou). Com piso, o Sonda instrumenta e mede o `<source>` inteiro mesmo com `--changed` ou `--files`, cujo veredito continua olhando só os arquivos deles; o total impresso é o do projeto |
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
| `SONDA_VIEWS` | não | pastas de views do Blade a medir, separadas por `PATH_SEPARATOR` |

## Como funciona

1. O bootstrap registra um autoloader na frente do Composer. Uma classe do diretório alvo é
   carregada de uma cópia instrumentada, guardada em cache por caminho, data de modificação e
   versão do instrumentador. Os arquivos da chave `files` do Composer que estão no alvo (helpers)
   são reservados antes de o autoload do Composer rodar e carregados instrumentados logo depois.
2. A instrumentação lê o arquivo com o `nikic/php-parser` e insere as sondas por posição de
   byte no fonte original, sem reimprimir o código. Nenhuma linha muda de número, e `__FILE__` e
   `__DIR__` continuam apontando para o arquivo original.
3. Só contam as sondas executadas durante cada teste, a mesma janela que o PHPUnit dá ao Xdebug:
   código executado em data provider não conta como coberto.
4. O que o autoloader não vê também vem instrumentado: um arquivo PHP do diretório alvo que o
   código carrega pelo caminho, com `include` ou `require` (uma migration do Laravel, um arquivo
   de rotas, um teste que faz `require` do arquivo), abre a cópia instrumentada. Um stream wrapper
   de `file://`, registrado só nos processos que rodam testes, faz essa troca; qualquer outra
   operação de arquivo segue para o wrapper do próprio PHP. Ele só entra quando o diretório alvo
   tem arquivos que o autoloader não carrega (que não declaram classe, interface, trait nem enum
   com nome, fora a chave `files` do Composer): cada arquivo que um wrapper abre guarda memória
   que o PHP só devolve no fim do processo, e numa suíte grande isso pesa. `--includes` e
   `--no-includes` forçam a escolha.
5. Ao fim de cada teste, o Sonda guarda quais sondas ele executou, com o arquivo, o tamanho
   (`#[Small]`, `#[Medium]`, `#[Large]`), a duração e se falhou. Conjuntos iguais de sondas são
   guardados uma vez só, porque os testes de uma suíte passam quase todos pelo mesmo código.
6. No fim de cada processo, a execução é gravada. O comando junta as execuções de todos os
   workers e monta o relatório com o php-code-coverage. Com `--covered-by`, cada linha e cada
   branch coberto leva o nome dos testes que o executaram, como com o Xdebug: no HTML, passar o
   mouse sobre uma linha mostra esses testes, e um teste que falhou aparece em vermelho.

## Views do Blade

Num projeto Laravel (com `artisan`), as views do Blade entram na medição junto com o `<source>`:
cada `.blade.php` de `resources/views`, e das pastas `resources/views` dos pacotes e módulos
guardados no projeto (fora de `vendor`, `node_modules`, `storage`, `public`, `tests` e
`bootstrap`), aparece nos relatórios com as linhas de diretiva e de echo (`@if`, `@foreach`,
`{{ }}`, `<x-componente>`) cobertas ou não, e com os branches das decisões (`@if`, `@unless`,
`@isset`, `@foreach`, `@forelse`, `@switch`, `??` e `?->` nos echos), no mesmo modo estrito do
PHP: um `@if` sem `@else` precisa do caso falso testado. A linha do `@endif` não é executável.
A view entra no veredito do `--changed` como um todo, como um método: linha mudada numa view
cobra as linhas e os branches dela inteira. `--views=a,b` acrescenta outras pastas de views;
`--no-blade` deixa as views de fora.

Como funciona: a primeira vez que um teste sobe a aplicação, o Sonda compila todas as views com
o compilador Blade dela (diretivas próprias incluídas), instrumenta o PHP compilado e grava o
resultado onde o Laravel vai incluí-lo, numa pasta de views compiladas só deste processo, apontada
por `VIEW_COMPILED_PATH` no ambiente (a configuração padrão do Laravel lê essa variável). O cache
do projeto em `storage/framework/views` não é tocado. As linhas do PHP compilado voltam para as
linhas do template por um mapa: um marcador inserido antes de cada construção sobrevive à
compilação e diz de que linha ela veio. A cópia instrumentada fica mais nova que o template, para
o Laravel não a compilar de novo sem sondas; com `--parallel`, cada worker tem a própria pasta, e a
subpasta por token que o teste paralelo do Laravel usa é criada antes da cópia.

O que o Blade e o Livewire escrevem em volta do template não conta como decisão, porque o desfecho
depende de como a view é renderizada e nenhum teste o controla: a guarda
`if (ExtendBlade::isRenderingLivewireComponent())` que o Livewire põe em cada `@if`, `@unless` e
`@foreach`; o salvar e restaurar de `$component`, `$attributes` e `$message` em volta de uma tag de
componente e do `@error`; o `$component->shouldRender()`; os laços, `if` e `??` com que `@props`,
`@aware` e `@livewire` tratam `$__key` e as outras variáveis de dois sublinhados. As linhas desse
código continuam medidas; as decisões do template (`@error`, `@forelse`, `@if`...) também.

Para o php-code-coverage, que só conta branches de funções declaradas, cada view é a função
`view`: a cópia do compilado que o relatório analisa no lugar do template declara essa função
em volta do código (com os `use` apagados, que não cabem numa função), e as sondas da view
levam esse nome. É ela que aparece na tabela de funções do HTML.

## Semântica dos branches

Um branch é um desfecho de decisão: a entrada de uma função, o corpo de `if`, `elseif`, `else`,
laço, `case`, `catch` e `finally`, o operando direito de `&&`, `||`, `and`, `or` e `??`, os
braços de `?:` e `match`, e o valor não nulo de `?->`.

No modo estrito, que é o padrão:

- Corpo vazio de `if`, `elseif`, `else` e laço é um desfecho como outro qualquer: ganha uma
  sonda sem linha, e conta como branch.
- `fn&(...) => expr` vira a closure que ele abrevia, `function &(...) use (...) { sonda; return expr; }`,
  com as variáveis da expressão capturadas por valor como a arrow function faz; `&get => expr`
  vira `&get { sonda; return expr; }`. A referência devolvida fica intacta.
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

- Views do Blade: só as pastas `resources/views` do projeto são achadas sozinhas; outra pasta
  entra com `--views`.
- Um arquivo da chave `files` do Composer que outro arquivo do diretório alvo também carrega por
  `require` direto seria declarado duas vezes, da cópia instrumentada e do próprio caminho. O
  comando recusa rodar nesse caso, resolvendo `__DIR__`, `__FILE__` e `dirname()` no argumento
  do `require`.
- A linha de um `catch` pela qual passou uma exceção de outro tipo aparece como não coberta
  (o Xdebug a conta como executada).
- Testes que leem o arquivo da classe por reflection veem a cópia instrumentada.

## Desenvolvimento

```bash
composer check          # pint, phpstan, rector (dry-run) e a suíte
composer format         # rector e pint
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --path-coverage --coverage-text
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --mutate --class='Sonda\Probe'
php tools/phpunit-for-installed-coverage.php   # phpunit.xml sem as classes de compatibilidade da outra versão do php-code-coverage, para a cobertura de 100%
php tools/lint-corpus.php vendor 1   # instrumenta um diretório inteiro e confere que o PHP continua válido
php tools/compare.php xdebug.cov sonda.cov -v   # compara dois .cov linha a linha e método a método
sonda compare <pasta de saída>   # na raiz de um projeto: Sonda, Xdebug com paths e a comparação, com summary.txt
```

O `sonda compare` (o `tools/compare-with-xdebug.sh`; `--processes`, `--batch` e `--xdebug` viram
as variáveis abaixo) roda a suíte duas vezes, com o Sonda e com o Xdebug em `--parallel`, e
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
