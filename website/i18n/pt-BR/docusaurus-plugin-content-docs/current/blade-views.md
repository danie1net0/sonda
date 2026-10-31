---
title: Views do Blade
sidebar_position: 8
description: Views do Laravel medidas linha a linha e branch a branch, instrumentando o template compilado.
---

Num projeto Laravel (um com `artisan`), as views do Blade entram na medição junto do `<source>`.

## O que é medido

Cada `.blade.php` em `resources/views`, e nas pastas `resources/views` dos pacotes e módulos
mantidos dentro do projeto (fora de `vendor`, `node_modules`, `storage`, `public`, `tests` e
`bootstrap`), aparece nos relatórios com as linhas de diretiva e de echo (`@if`, `@foreach`,
`{{ }}`, `<x-component>`) cobertas ou não, e com os branches das decisões (`@if`, `@unless`,
`@isset`, `@foreach`, `@forelse`, `@switch`, `??` e `?->` nos echos), no mesmo modo estrito do PHP:
um `@if` sem `@else` precisa do caso falso testado. A linha do `@endif` não é executável.

Uma view entra no veredito do `--changed` inteira, como um método: uma linha alterada numa view
cobra as linhas e os branches da view toda.

`--views=a,b` acrescenta outras pastas de views; `--no-blade` deixa as views de fora. Só as pastas
`resources/views` do projeto são achadas sozinhas.

## Como funciona

Na primeira vez que um teste sobe a aplicação, o Sonda compila todas as views com o compilador Blade
da própria aplicação (diretivas próprias incluídas), instrumenta o PHP compilado e grava o resultado
onde o Laravel vai incluí-lo, numa pasta de views compiladas só deste processo, apontada por
`VIEW_COMPILED_PATH` no ambiente (a configuração padrão do Laravel lê essa variável). O cache do
projeto em `storage/framework/views` não é tocado.

As linhas do PHP compilado mapeiam de volta para as linhas do template: um marcador inserido antes
de cada construção sobrevive à compilação e diz de que linha veio. A cópia instrumentada é mantida
mais nova que o template, para o Laravel não compilá-la de novo sem sondas; com `--parallel`, cada
worker tem a própria pasta, e a subpasta por token que o teste paralelo do Laravel usa é criada antes
da cópia.

Quando as views compiladas não vão parar nessa pasta (uma configuração que sobrescreve
`VIEW_COMPILED_PATH`), as views não são medidas e o processo avisa no stderr:

```
Sonda: the compiled views are not in <dir> (VIEW_COMPILED_PATH did not reach the configuration): views not measured.
```

Uma view que não compila fica de fora, com o erro dela: `Sonda: the view <view> did not compile and is not measured: <error>`.

## O que não conta como decisão

O que o Blade e o Livewire escrevem em volta do template não conta como decisão, porque o desfecho
depende de como a view é renderizada e nenhum teste controla isso:

- a guarda `if (ExtendBlade::isRenderingLivewireComponent())` que o Livewire põe em todo `@if`,
  `@unless` e `@foreach`;
- o salvar e restaurar de `$component`, `$attributes` e `$message` em volta de uma tag de componente
  e do `@error`;
- o `$component->shouldRender()`;
- os laços, `if` e `??` com que `@props`, `@aware` e `@livewire` tratam `$__key` e as outras
  variáveis com dois sublinhados.

As linhas desse código continuam medidas; as decisões do template (`@error`, `@forelse`, `@if`...)
também.

## No relatório

Para o php-code-coverage, que só conta branches de funções declaradas, cada view é a função `view`:
a cópia do código compilado que o relatório analisa no lugar do template declara essa função em
volta do código (com os `use` removidos, que não cabem numa função), e as sondas da view levam esse
nome. É o que aparece na tabela de funções do HTML.
