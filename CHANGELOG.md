## [1.13.2](https://github.com/danie1net0/sonda/compare/v1.13.1...v1.13.2) (2026-10-21)


### Bug Fixes

* **phpunit:** lê o arquivo dos testes do Pest anterior ao 4.7, que o guarda privado ([fa5c734](https://github.com/danie1net0/sonda/commit/fa5c734cd9ffdb2ec4636f4ced62f2ccc154b58e))

## [1.13.1](https://github.com/danie1net0/sonda/compare/v1.13.0...v1.13.1) (2026-10-21)


### Bug Fixes

* **composer:** o pacote sai no Packagist como ddr/sonda ([22465f0](https://github.com/danie1net0/sonda/commit/22465f0d8a0279a34e96463df937dde65fe24db9))

# [1.13.0](https://github.com/danie1net0/sonda/compare/v1.12.0...v1.13.0) (2026-10-21)


### Features

* suporte a PHPUnit 11 e Pest 3 ([1611f1e](https://github.com/danie1net0/sonda/commit/1611f1e9b22d4cd7abbe763fa0a1a50fab0a23cc))

# [1.12.0](https://github.com/danie1net0/sonda/compare/v1.11.1...v1.12.0) (2026-10-21)


### Features

* **cli:** --map guarda o mapa do --affected onde o cache do CI alcança ([214a5a2](https://github.com/danie1net0/sonda/commit/214a5a2b5f1964812d732c75a78268fb8e276c10))
* **relatorios:** --code-quality aponta no diff do MR o que ficou sem teste ([ef5960e](https://github.com/danie1net0/sonda/commit/ef5960ee6ed1dcfda431d2a5ba8a0006f929a4dd))

## [1.11.1](https://github.com/danie1net0/sonda/compare/v1.11.0...v1.11.1) (2026-10-21)


### Bug Fixes

* **git:** --changed acha as mudanças de projeto em subdiretório do repositório ([c394fb7](https://github.com/danie1net0/sonda/commit/c394fb72e213faefb26e62f7596036d8981a1c17))

# [1.11.0](https://github.com/danie1net0/sonda/compare/v1.10.4...v1.11.0) (2026-10-19)


### Features

* **cli:** --affected roda só os testes que executam o que mudou ([b737b55](https://github.com/danie1net0/sonda/commit/b737b55ef49938af9f6e23a08d1561d8c70ec830))
* **relatorios:** --covered-by diz que testes executaram cada linha e branch ([20a459f](https://github.com/danie1net0/sonda/commit/20a459f443f4c110b3a99d3bf92667ff2933f34f))

## [1.10.4](https://github.com/danie1net0/sonda/compare/v1.10.3...v1.10.4) (2026-10-19)


### Bug Fixes

* **cli:** com piso, o projeto inteiro é medido mesmo com --changed e --files ([fe981f6](https://github.com/danie1net0/sonda/commit/fe981f6210878f013fa9abc59f5358ba836514a5))

## [1.10.3](https://github.com/danie1net0/sonda/compare/v1.10.2...v1.10.3) (2026-10-18)


### Bug Fixes

* **sondas:** if sem else antes do endif não captura o else: sintético ([5152a43](https://github.com/danie1net0/sonda/commit/5152a43345b3dba7c48f92759dcb54d242bb7ebc))

## [1.10.2](https://github.com/danie1net0/sonda/compare/v1.10.1...v1.10.2) (2026-10-18)


### Bug Fixes

* **deps:** recursion-context 7.0.1 no mínimo, a do SplObjectStorage::offsetExists ([0fbe743](https://github.com/danie1net0/sonda/commit/0fbe743827fbe655b4a8070cf77b09432e07dd35))

## [1.10.1](https://github.com/danie1net0/sonda/compare/v1.10.0...v1.10.1) (2026-10-18)


### Bug Fixes

* **blade:** o que o Blade e o Livewire escrevem em volta do template não conta como decisão ([7c9cc51](https://github.com/danie1net0/sonda/commit/7c9cc51e5b51c1b09ca529dd8855b81c9cf0d564))

# [1.10.0](https://github.com/danie1net0/sonda/compare/v1.9.0...v1.10.0) (2026-10-16)


### Features

* **cli:** sem nada mudado no source, relatórios vazios ou, com piso, o projeto inteiro ([7632624](https://github.com/danie1net0/sonda/commit/76326243dfe40db9cff06b5c88019537fe3d9661))

# [1.9.0](https://github.com/danie1net0/sonda/compare/v1.8.0...v1.9.0) (2026-10-16)


### Features

* fn& e &get ganham sonda, aviso de require direto, shebang preservado ([015ce43](https://github.com/danie1net0/sonda/commit/015ce438ccb06f94067ae379659ffbe2bb44a189))

# [1.8.0](https://github.com/danie1net0/sonda/compare/v1.7.0...v1.8.0) (2026-10-16)


### Bug Fixes

* **tools:** compare-with-xdebug.sh em lotes só toma como pasta de testes um argumento que seja pasta ([0198e61](https://github.com/danie1net0/sonda/commit/0198e61b935522a73bb3b8578ce96d5a4e03c310))


### Features

* **cli:** --text, --min-lines/--min-branches e o comando compare; suíte semanal com dependências novas ([2f80108](https://github.com/danie1net0/sonda/commit/2f80108e209fb371894943291951cef8d3dfdbd5))

# [1.7.0](https://github.com/danie1net0/sonda/compare/v1.6.0...v1.7.0) (2026-10-16)


### Bug Fixes

* **cli:** caminhos reais para os workers, para um temporário atrás de symlink não perder os dados das views ([d896e64](https://github.com/danie1net0/sonda/commit/d896e649b399ec4de519832100e441903e8e5210))


### Features

* **bootstrap:** arquivos da chave files do Composer que estão no alvo são instrumentados ([abd3426](https://github.com/danie1net0/sonda/commit/abd3426d31b37f5c045cd01a3e4f0c8bf339123f))
* **cli:** vários caminhos de teste rodam em paralelo, por uma configuração gerada ([1d4d787](https://github.com/danie1net0/sonda/commit/1d4d787591e53dc7a2090b5678ba51a1681ca05c))
* **sondas:** corpo vazio de if e de laço conta como branch; property hook de expressão ganha sonda ([ac4cee5](https://github.com/danie1net0/sonda/commit/ac4cee5708b9d5e6ea57b16d591cf5c4540dae9b))

# [1.6.0](https://github.com/danie1net0/sonda/compare/v1.5.0...v1.6.0) (2026-10-16)


### Features

* **blade:** branches das views, pastas de views de pacotes e módulos, e --views ([329aab0](https://github.com/danie1net0/sonda/commit/329aab005a0ad350d5c6a1df78aa86fa7cb66afc))

# [1.5.0](https://github.com/danie1net0/sonda/compare/v1.4.0...v1.5.0) (2026-10-16)


### Features

* **blade:** mede as views do Blade de projetos Laravel, também nos workers do ParaTest ([b90335b](https://github.com/danie1net0/sonda/commit/b90335bb0dd2b005e39566ae129818741182eecc))

# [1.4.0](https://github.com/danie1net0/sonda/compare/v1.3.0...v1.4.0) (2026-10-16)


### Features

* **cli:** --cobertura, base sem merge-base, vários caminhos em série e lotes no compare-with-xdebug ([02c36f7](https://github.com/danie1net0/sonda/commit/02c36f771423247ad55b994b8323d6a7925aa9fe))

# [1.3.0](https://github.com/danie1net0/sonda/compare/v1.2.1...v1.3.0) (2026-10-16)


### Features

* **changed:** veredito só do que mudou, com as linhas alteradas e os métodos que elas tocam ([b9aecbd](https://github.com/danie1net0/sonda/commit/b9aecbdd4031027149c330ce4e8fbe2d8906b17b))

## [1.2.1](https://github.com/danie1net0/sonda/compare/v1.2.0...v1.2.1) (2026-10-15)


### Bug Fixes

* **tools:** compare-with-xdebug.sh lê só a própria pasta de ini, sem o conf.d padrão ([cc0882e](https://github.com/danie1net0/sonda/commit/cc0882e81e7eb65ae388a028fbb7d42308264041))

# [1.2.0](https://github.com/danie1net0/sonda/compare/v1.1.2...v1.2.0) (2026-10-14)


### Features

* **estrito:** operando esquerdo de &&, ||, and e or decidindo sozinho conta como branch ([e5a40c0](https://github.com/danie1net0/sonda/commit/e5a40c0fca40f937364cc9c20065f0c46cd82dfe))

## [1.1.2](https://github.com/danie1net0/sonda/compare/v1.1.1...v1.1.2) (2026-10-14)


### Bug Fixes

* **tools:** compare.php junta o callable de primeira classe do Xdebug e só aponta cegueira onde o Sonda diz 100% ([c448f56](https://github.com/danie1net0/sonda/commit/c448f56b8cb71df16eb27a6af3d5d0584e974d1d))

## [1.1.1](https://github.com/danie1net0/sonda/compare/v1.1.0...v1.1.1) (2026-10-14)


### Bug Fixes

* cache e execuções em diretório privado do usuário ([bd49f77](https://github.com/danie1net0/sonda/commit/bd49f773220048f49437315d2a206493805e9432))

# [1.1.0](https://github.com/danie1net0/sonda/compare/v1.0.0...v1.1.0) (2026-10-14)


### Features

* comando sonda test, sem instalar nada no projeto ([845a6e5](https://github.com/danie1net0/sonda/commit/845a6e5ca9bba248aff69eb18330f3a2277745a2))

# 1.0.0 (2026-10-14)


### Bug Fixes

* arquivo temporário único no lint do corpus ([403e101](https://github.com/danie1net0/sonda/commit/403e101620fb5bda81c22494187a886327df2573))
* sondas que mudavam o comportamento ou a cobertura no contata-ai ([67851b4](https://github.com/danie1net0/sonda/commit/67851b46b0e181a5f9535ffa7ebb970af50ffe5c))


### Features

* medição com --parallel (ParaTest) ([934bca4](https://github.com/danie1net0/sonda/commit/934bca410d52313044e54e4aabd4c1557aa2c86f))
* protótipo de cobertura de linhas e branches por instrumentação do fonte ([58da2a9](https://github.com/danie1net0/sonda/commit/58da2a9e3b00986564f315a5c7babdafb1e4ddd9))
