---
title: Limitações conhecidas
sidebar_position: 13
description: O que o Sonda não faz, ou faz diferente do Xdebug.
---

As limitações achadas até agora; uma issue é bem-vinda para qualquer outra.

- **Views do Blade**: só as pastas `resources/views` do projeto são achadas sozinhas; outra pasta
  entra com `--views`.
- **Arquivo da chave `files` do Composer carregado duas vezes.** Um arquivo da chave `files` do
  Composer que outro arquivo do diretório alvo também carrega por `require` direto seria declarado
  duas vezes, da cópia instrumentada e do próprio caminho. O comando se recusa a rodar nesse caso,
  resolvendo `__DIR__`, `__FILE__` e `dirname()` no argumento do `require`:

  ```
  app/helpers.php is in Composer's files key and is still loaded by a direct require in app/Boot.php:3: under Sonda it would be declared twice. Drop the require.
  ```

- **Um `catch` que uma exceção atravessou.** A linha de um `catch` que uma exceção de outro tipo
  atravessou aparece como descoberta (o Xdebug a conta como executada).
- **Reflexão sobre o arquivo da classe.** Testes que leem o arquivo da classe por reflexão veem a
  cópia instrumentada.
- **Requisitos.** PHP 8.4 ou mais novo; PHPUnit 11.5 (Pest 3) ou 12 (Pest 4) no projeto medido. O
  Sonda usa a fachada de eventos do PHPUnit, que é interna, e o CI roda a suíte nas duas versões, na
  mais baixa e na mais recente de cada uma.
