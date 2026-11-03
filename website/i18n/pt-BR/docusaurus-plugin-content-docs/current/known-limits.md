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
- **Linhas de fechamento no PHPUnit 13.** O php-code-coverage 14 conta como executáveis algumas
  linhas de fechamento em que o Sonda não põe sonda, como o `})($valor);` de uma closure chamada
  onde é escrita. Essa linha aparece como descoberta mesmo quando rodou; é raro, um punhado de
  linhas em centenas de arquivos. O PHPUnit 11 e o 12 não a contam.
- **Memória do relatório.** Depois da suíte, o processo do `sonda` junta as execuções de todos os
  workers e monta o relatório, e uma suíte grande precisa de memória ali também. Ele usa o
  `memory_limit` do `<php><ini>` do `phpunit.xml` quando esse é maior que o dele. Sem um, uma
  suíte de milhares de testes pode precisar de `php -d memory_limit=-1 vendor/bin/sonda test`.
- **Requisitos.** PHP 8.4 ou mais novo; PHPUnit 11.5 (Pest 3), 12 (Pest 4) ou 13 (Pest 5) no
  projeto medido. O Sonda usa a fachada de eventos do PHPUnit, que é interna, e o CI roda a suíte nas
  três versões, na mais baixa e na mais recente de cada uma.
