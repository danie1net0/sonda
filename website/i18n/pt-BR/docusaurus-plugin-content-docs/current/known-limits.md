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
- **As variáveis de uma função.** No modo rápido (o padrão), cada função tem uma referência local
  chamada `$__sonda`, que o `get_defined_vars()` devolve junto com as outras. O `--no-fast` a tira.
- **`__COMPILER_HALT_OFFSET__`.** Um arquivo que lê os dados depois do `__halt_compiler()` recebe o
  offset da cópia instrumentada com o caminho do arquivo original, e lê os bytes errados.
- **Carregado antes da instrumentação, num processo que não pode recomeçar.** Um arquivo do alvo
  que já está carregado quando o bootstrap roda faz o processo começar de novo (veja como
  funciona). Um teste que o PHPUnit roda em processo separado, e um worker do paralelo iniciado sem
  o `PhpExecutableFinder` do Symfony, não podem: o Sonda diz quais arquivos ficam sem medição
  naquele processo.
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
