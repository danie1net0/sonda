---
title: Como funciona
sidebar_position: 7
description: Sondas por offset de byte, um cache de cópias instrumentadas, um autoloader antes do do Composer, uma execução por worker.
---

O Sonda não reescreve nada que você veja: as cópias instrumentadas ficam num cache fora do projeto,
os arquivos originais mantêm os números de linha, e o relatório sai do php-code-coverage.

1. **Um autoloader antes do do Composer.** O bootstrap registra um autoloader antes do do Composer.
   Uma classe do diretório alvo é carregada de uma cópia instrumentada, em cache por caminho, data de
   modificação e versão do instrumentador. Os arquivos da chave `files` do Composer que estão dentro
   do alvo (helpers) são reivindicados antes de o autoload do Composer rodar e carregados
   instrumentados logo depois.
2. **Sondas por offset de byte.** A instrumentação lê o arquivo com o `nikic/php-parser` e insere
   as sondas por offset de byte no fonte original, sem reimprimir o código. Nenhuma linha muda de
   número, e `__FILE__` e `__DIR__` continuam apontando para o arquivo original. Uma sonda é uma
   atribuição num array (`Hits::$current[<id do arquivo>][<id da sonda>] = 1`); com o modo rápido,
   que é o padrão, uma referência local é ligada uma vez por função, e cada sonda vira uma única
   atribuição.
3. **A janela de cada teste.** Só as sondas executadas durante cada teste contam, a mesma janela
   que o PHPUnit dá ao Xdebug: código executado num data provider não conta como coberto.
4. **Arquivos carregados pelo caminho.** O que o autoloader nunca vê vem instrumentado também: um
   arquivo PHP do diretório alvo que o código carrega pelo caminho, com `include` ou `require` (uma
   migration do Laravel, um arquivo de rotas, um teste que faz `require` do arquivo), abre a cópia
   instrumentada. Um stream wrapper de `file://`, registrado só nos processos que rodam testes, faz
   essa troca; toda outra operação de arquivo segue para o wrapper do próprio PHP. Ele só entra
   quando o diretório alvo tem arquivos que o autoloader não carrega (os que não declaram classe,
   interface, trait ou enum com nome, fora a chave `files` do Composer). `--includes` e
   `--no-includes` forçam a escolha.
5. **O que cada teste executou.** No fim de cada teste, o Sonda registra quais sondas ele executou,
   com o arquivo, o tamanho (`#[Small]`, `#[Medium]`, `#[Large]`), a duração e se falhou. Conjuntos
   iguais de sondas são guardados uma vez, porque os testes de uma suíte quase todos passam pelo
   mesmo código. É isso que o `--affected` e o `--covered-by` leem.
6. **Uma execução por processo, um relatório.** No fim de cada processo (cada worker do ParaTest
   incluído), a execução é gravada. O comando junta as execuções de todos os workers e monta o
   relatório com o php-code-coverage. Com `--covered-by`, cada linha e branch cobertos levam os nomes
   dos testes que os executaram, como no Xdebug: no HTML, passar o mouse numa linha mostra esses
   testes, e um teste que falhou aparece em vermelho.

## Custo

Uma sonda é uma atribuição num array, então o código instrumentado roda quase na velocidade do
original: na suíte Laravel da [comparação](comparison-with-xdebug.md), 174s com o Sonda contra 178s
sem cobertura, e 40s contra 42s com 10 workers do ParaTest. Pico de memória por worker, nos 10
workers de `tests/Feature/Filament` dessa suíte, com o wrapper de include ligado: 52 MB. Até a
1.14.2, esse wrapper prendia memória a cada operação de arquivo, e o pico chegava a 107 MB por worker.

## Onde as peças ficam

Cache e execuções ficam fora do projeto, em `$XDG_CACHE_HOME/sonda` ou `~/.cache/sonda`, num
diretório privado do usuário (modo 0700): as cópias instrumentadas rodam como código, então ninguém
mais pode escrever ali. O Sonda recusa um diretório de cache que pertença a outro usuário ou esteja
aberto a outros. O mapa de testes do `--affected` fica ali também, a menos que `--map` o mova.
