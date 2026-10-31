# Sonda

[English](README.md) · [Documentação](https://danie1net0.github.io/sonda/pt-BR/) · [Packagist](https://packagist.org/packages/ddr/sonda)

Cobertura de linhas e de branches para PHP por instrumentação do fonte: sem Xdebug, sem PCOV, a
suíte roda no tempo normal dela, e cada decisão precisa mostrar os dois desfechos.

O Sonda insere sondas no código antes de carregá-lo, como o Istanbul faz no JavaScript. Uma sonda é
uma atribuição num array; não há interceptação de opcode nem enumeração de caminhos, que é o que
deixa o Xdebug lento e pesado. Numa suíte Laravel de 4.514 testes: 178 s sem cobertura, 174 s com
o Sonda, 671 s com o Xdebug só de linhas, e o Xdebug com `--path-coverage` não terminou em uma
hora. Os números foram validados contra o path coverage do Xdebug nessa suíte inteira.

## Instalação

PHP 8.4+, PHPUnit 12 ou Pest 4.

```bash
composer require --dev ddr/sonda
```

## Uso

```bash
vendor/bin/sonda test                               # a suíte inteira, o <source> inteiro do phpunit.xml
vendor/bin/sonda test --changed=origin/master       # só os arquivos que o branch mudou; falha se sobrar algo descoberto
vendor/bin/sonda test --changed=origin/master --affected   # e só os testes que os executam
vendor/bin/sonda test --html=coverage/html --cobertura=coverage/cobertura.xml
```

```
Coverage: lines 12118/12118 (100.00%), branches 4857/4857 (100.00%)
Of what changed: lines 86/86 (100.00%), branches 31/31 (100.00%)
The changed files are 100% covered.
```

Os branches são contados em modo estrito por padrão: um `if` sem `else` precisa dos dois desfechos
testados, `?->` conta o lado nulo, e `&&`/`||` precisam fazer curto-circuito ao menos uma vez. As
views do Blade também são medidas, linha e branch.

A documentação tem o [guia de início](https://danie1net0.github.io/sonda/pt-BR/docs/getting-started),
a [referência da CLI](https://danie1net0.github.io/sonda/pt-BR/docs/usage/cli-reference), a
[semântica dos branches](https://danie1net0.github.io/sonda/pt-BR/docs/branch-semantics), a
[comparação com o Xdebug](https://danie1net0.github.io/sonda/pt-BR/docs/comparison-with-xdebug) e receitas para
[GitLab](https://danie1net0.github.io/sonda/pt-BR/docs/ci/gitlab) e
[GitHub Actions](https://danie1net0.github.io/sonda/pt-BR/docs/ci/github-actions).

## Contribuir

Veja o [CONTRIBUTING.md](CONTRIBUTING.md). Bug vai para as
[issues](https://github.com/danie1net0/sonda/issues) com a saída de `sonda --version`.

## Licença

[MIT](LICENSE.md)
