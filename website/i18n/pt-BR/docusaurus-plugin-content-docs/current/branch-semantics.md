---
title: Semântica dos branches
sidebar_position: 9
description: O que o Sonda conta como branch, o que o modo estrito exige e o que ele deixa de fora de propósito.
---

Um branch é um desfecho de uma decisão: a entrada de uma função, o corpo de `if`, `elseif`, `else`,
de um laço, de `case`, `catch` e `finally`, o operando direito de `&&`, `||`, `and`, `or` e `??`,
os braços de `?:` e `match`, e o valor não nulo de `?->`.

## Modo estrito

O modo estrito é o padrão; `--no-strict` desliga a primeira e a quarta regras abaixo (o `else`
sintetizado e o lado nulo de `?->`).

- **Corpo vazio é desfecho.** Um corpo vazio de `if`, `elseif`, `else` e de laços é um desfecho
  como qualquer outro: ganha uma sonda sem linha, e conta como branch.

  ```php
  if ($order->isPaid()) {
      // nothing to do: this outcome still has to be reached by a test
  }
  ```

- **Arrow functions viram closures.** `fn&(...) => expr` vira a closure que abrevia,
  `function &(...) use (...) { probe; return expr; }`, com as variáveis da expressão capturadas por
  valor como a arrow function faz; `&get => expr` vira `&get { probe; return expr; }`. A referência
  devolvida fica intacta.

- **Todo `if` precisa do `else`.** Todo `if` sem `else` ganha um `else` sintetizado: os dois
  desfechos precisam ser testados. O Xdebug conta blocos básicos, então um `if` sem `else` cuja
  condição é sempre verdadeira dá 100% lá e não no Sonda.

  ```php
  public function total(): int
  {
      if ($this->discount !== null) {   // a test with a discount, and one without
          return $this->subtotal - $this->discount;
      }

      return $this->subtotal;
  }
  ```

- **`?->` conta os dois lados.** O desfecho nulo e o não nulo de toda chamada nullsafe.

  ```php
  $name = $order->customer?->name();   // a test with a customer, and one with null
  ```

- **O curto-circuito de `&&` e `||`.** O operando esquerdo de `&&`, `||`, `and` e `or` tem de
  decidir sozinho ao menos uma vez (o curto-circuito, quando o direito nem roda), como o Xdebug
  conta.

  ```php
  if ($user->isAdmin() || $user->owns($order)) {   // one test where isAdmin() is true, so owns() never runs
  ```

  Não no `??`: o lado esquerdo dele pode ser uma chave indefinida, que só esse operador lê sem
  aviso, então só o operando direito do `??` é um branch.

  ```php
  $limit = $options['limit'] ?? 10;   // only the fallback (right side) needs a test
  ```

## O que o Sonda deixa de fora de propósito

- **O braço implícito do `match`.** O Sonda não conta o branch implícito de "nenhum braço casou" do
  `match`, que o Xdebug conta e nenhum teste alcança quando os braços cobrem todos os casos de um
  enum.

  ```php
  return match ($status) {
      Status::Open => 'open',
      Status::Closed => 'closed',
  };   // the arms are the branches; the UnhandledMatchError path is not one
  ```

- **O desvio de fallback de uma função frameless.** O Sonda não conta o desvio de fallback que o
  Xdebug vê numa chamada a uma função nativa *frameless* (`in_array`, `max`, `preg_replace`,
  `str_contains`...) feita dentro de um namespace sem `use function`: é um detalhe do opcode, não
  uma decisão do código, e um `use function` só para calar a cobertura deixa de ser necessário.

  ```php
  namespace App;

  $found = in_array($id, $ids, true);   // no "branch" here for Sonda; Xdebug sees the fallback to the global function
  ```

- **First-class callables creditam o método.** Um método chamado como *first-class callable*
  (`$this->method(...)`) aparece no Xdebug como uma função solta com o nome do método, com as
  execuções, enquanto o método da classe fica em zero; o Sonda credita o método. O
  `tools/compare.php` funde as duas entradas antes de comparar.

A fidelidade dessas regras contra o Xdebug, medida numa suíte inteira, está em
[Comparação com o Xdebug](comparison-with-xdebug.md).
