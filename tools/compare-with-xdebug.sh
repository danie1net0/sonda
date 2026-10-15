#!/bin/bash
# Compara a cobertura do Sonda com a do Xdebug (path coverage) no projeto do diretório atual.
#
# Uso, na raiz do projeto medido:
#   DB_DATABASE=<banco privado> tools/compare-with-xdebug.sh <diretório de saída> [argumentos do runner...]
#
# Variáveis: PROCESSES (workers do ParaTest, padrão 3) e XDEBUG_SO (caminho do xdebug.so).
# Saída: sonda.cov, xdebug.cov, compare.txt e summary.txt no diretório informado.
# O Xdebug com paths custa ~30x o tempo da suíte: rodar com a máquina livre (caffeinate evita o sleep).
set -uo pipefail

OUT=${1:?informe o diretório de saída}
shift
ROOT=$(pwd)
SONDA=$(cd "$(dirname "$0")/.." && pwd)
PROCESSES=${PROCESSES:-3}
XDEBUG_SO=${XDEBUG_SO:-/opt/homebrew/opt/xdebug@8.5/xdebug.so}
CAFFEINATE=$(command -v caffeinate >/dev/null && echo "caffeinate -i" || echo "")

[ -f "$ROOT/phpunit.xml" ] || { echo "Rode na raiz de um projeto com phpunit.xml." >&2; exit 1; }
[ -f "$ROOT/vendor/autoload.php" ] || { echo "Falta vendor/autoload.php: composer install." >&2; exit 1; }
[ -f "$XDEBUG_SO" ] || { echo "Xdebug não encontrado em $XDEBUG_SO (XDEBUG_SO=...)." >&2; exit 1; }
RUNNER=vendor/bin/pest; [ -x "$RUNNER" ] || RUNNER=vendor/bin/paratest
[ -x "$RUNNER" ] || { echo "Falta vendor/bin/pest ou vendor/bin/paratest." >&2; exit 1; }

mkdir -p "$OUT/ini"
OUT=$(cd "$OUT" && pwd)
printf 'zend_extension=%s\nxdebug.mode=coverage\nmemory_limit=-1\n' "$XDEBUG_SO" > "$OUT/ini/xdebug.ini"

step() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$OUT/summary.txt"; }
tests_line() { sed 's/\x1b\[[0-9;]*m//g' "$1" | grep -E 'Tests:' | tail -1 | sed 's/^ *//'; }

: > "$OUT/summary.txt"
step "projeto $ROOT, árvore: $(git -C "$ROOT" status --short 2>/dev/null | wc -l | tr -d ' ') arquivo(s) modificado(s)"

# 1. Sonda, com o <source> do phpunit.xml do projeto.
step "sonda: início"
start=$(date +%s)
$CAFFEINATE "$SONDA/bin/sonda" test --cov="$OUT/sonda.cov" "$@" > "$OUT/sonda.log" 2>&1
step "sonda: exit=$? $(( $(date +%s) - start ))s | $(tests_line "$OUT/sonda.log") | $(sed 's/\x1b\[[0-9;]*m//g' "$OUT/sonda.log" | grep -E '^Cobertura' | tail -1)"

# 2. phpunit.xml com caminhos absolutos e pathCoverage ligado: --path-coverage não passa pelo ParaTest,
#    e um config fora da raiz resolve caminhos relativos a partir da própria pasta.
sed -e "s#bootstrap=\"\([^/\"][^\"]*\)\"#bootstrap=\"$ROOT/\1\"#" \
    -e "s#\(<directory[^>]*>\)\([^/]\)#\1$ROOT/\2#" \
    -e "s#\(<file[^>]*>\)\([^/]\)#\1$ROOT/\2#" \
    "$ROOT/phpunit.xml" > "$OUT/phpunit.xdebug.xml"
if ! grep -q '<coverage' "$OUT/phpunit.xdebug.xml"; then
    sed 's#</source>#</source>\n  <coverage pathCoverage="true"/>#' "$OUT/phpunit.xdebug.xml" > "$OUT/phpunit.tmp" && mv "$OUT/phpunit.tmp" "$OUT/phpunit.xdebug.xml"
elif ! grep -q 'pathCoverage=' "$OUT/phpunit.xdebug.xml"; then
    sed 's#<coverage#<coverage pathCoverage="true"#' "$OUT/phpunit.xdebug.xml" > "$OUT/phpunit.tmp" && mv "$OUT/phpunit.tmp" "$OUT/phpunit.xdebug.xml"
fi

# 3. Xdebug nos workers via PHP_INI_SCAN_DIR (opção -d não chega aos processos filhos). Só a pasta
#    deste script é lida, sem o conf.d padrão: um Xdebug já carregado ali seria carregado duas vezes,
#    e o PCOV, se houver, disputa a cobertura com ele.
step "xdebug: início, $PROCESSES workers"
start=$(date +%s)
PHP_INI_SCAN_DIR="$OUT/ini" XDEBUG_MODE=coverage LARAVEL_PARALLEL_TESTING=1 \
    $CAFFEINATE php "$RUNNER" --parallel --processes="$PROCESSES" -c "$OUT/phpunit.xdebug.xml" --coverage-php="$OUT/xdebug.cov" "$@" > "$OUT/xdebug.log" 2>&1
step "xdebug: exit=$? $(( $(date +%s) - start ))s | $(tests_line "$OUT/xdebug.log")"

# 4. Comparação linha a linha e método a método.
[ -f "$OUT/xdebug.cov" ] || { step "xdebug.cov não foi gerado: ver $OUT/xdebug.log"; exit 1; }
php -d memory_limit=-1 "$SONDA/tools/compare.php" "$OUT/xdebug.cov" "$OUT/sonda.cov" -v > "$OUT/compare.txt" 2>&1
step "compare: exit=$?"
grep -E 'LINES|only|hit,|BRANCH|METHOD|SOUNDNESS' "$OUT/compare.txt" | tee -a "$OUT/summary.txt"
step "fim"
