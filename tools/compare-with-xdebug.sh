#!/bin/bash
# Compara a cobertura do Sonda com a do Xdebug (path coverage) no projeto do diretório atual.
#
# Uso, na raiz do projeto medido:
#   DB_DATABASE=<banco privado> tools/compare-with-xdebug.sh <diretório de saída> [argumentos do runner...]
#
# Variáveis: PROCESSES (workers do ParaTest ou filas de lotes, padrão 3), XDEBUG_SO (caminho do
# xdebug.so) e BATCH (testes por lote; com ela o lado Xdebug roda em lotes, ver abaixo).
# Saída: sonda.cov, xdebug.cov, compare.txt e summary.txt no diretório informado.
#
# O Xdebug com paths custa ~30x o tempo da suíte, e o php-code-coverage acumula, a cada teste, os
# ids de teste em todos os branches e caminhos dos arquivos carregados: numa suíte grande o custo
# por teste cresce até a rodada não terminar. BATCH=80 divide os arquivos de teste em lotes de até
# 80 testes, cada um num processo novo, PROCESSES filas em paralelo, e junta os .cov no fim com
# CodeCoverage::merge(). Cada fila usa o banco ${DB_DATABASE}_q<N>, criado com createdb quando
# houver. Rodar com a máquina livre (caffeinate evita o sleep).
set -uo pipefail

OUT=${1:?informe o diretório de saída}
shift
ROOT=$(pwd)
SONDA=$(cd "$(dirname "$0")/.." && pwd)
PROCESSES=${PROCESSES:-3}
BATCH=${BATCH:-0}
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

# Lotes de até $BATCH testes (arquivo de teste inteiro), distribuídos por contagem entre $PROCESSES
# filas; cada lote é um processo novo, cada fila tem o próprio banco; os .cov são juntados no fim.
xdebug_in_batches() {
    local tests_root=$1
    mkdir -p "$OUT/batches" "$OUT/queues"
    rm -f "$OUT/batches"/* "$OUT/queues"/*
    for f in $(find "$tests_root" -name '*Test.php' | sort); do
        # Testes do Pest (it, test, arch) ou métodos test* do PHPUnit; ao menos um por arquivo.
        echo "$(grep -cE '^\s*(it|test|arch)\(|function test' "$f" | awk '{print ($1 > 0) ? $1 : 1}') $f"
    done > "$OUT/queues/files.txt"
    python3 - "$OUT/queues" "$PROCESSES" "$BATCH" <<'PY'
import sys
out, queues, cap = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
files = sorted(((int(c), f) for c, f in (line.split() for line in open(f"{out}/files.txt"))), reverse=True)
batches = []
for count, path in files:
    for batch in batches:
        if batch[0] + count <= cap:
            batch[0] += count; batch[1].append(path); break
    else:
        batches.append([count, [path]])
batches.sort(key=lambda b: -b[0])
loads = [0] * queues
lists = [[] for _ in range(queues)]
for number, (count, paths) in enumerate(batches, start=1):
    i = loads.index(min(loads))
    loads[i] += count
    lists[i].append(f"lote-{number:03d} " + " ".join(paths))
for i, items in enumerate(lists, start=1):
    open(f"{out}/queue-{i}.txt", "w").write("\n".join(items) + "\n")
PY
    local total; total=$(cat "$OUT/queues"/queue-*.txt | grep -c .)
    step "xdebug em lotes: início, $total lotes de até $BATCH testes em $PROCESSES filas"
    local start; start=$(date +%s)
    local queue
    for queue in $(seq 1 "$PROCESSES"); do
        (
            db=${DB_DATABASE:-}
            if [ -n "$db" ]; then
                db="${db}_q${queue}"
                command -v createdb >/dev/null && createdb "$db" 2>/dev/null
            fi
            while read -r name files; do
                [ -z "$name" ] && continue
                s=$(date +%s)
                # O Pest aceita vários caminhos; o PHPUnit, um só: aí cada arquivo do lote roda sozinho.
                if [ "$RUNNER" = vendor/bin/pest ]; then
                    DB_DATABASE=$db php "$RUNNER" -c "$OUT/phpunit.xdebug.xml" --do-not-cache-result --coverage-php="$OUT/batches/$name.cov" $files > "$OUT/batches/$name.log" 2>&1
                    status=$?
                else
                    status=0
                    index=0
                    for file in $files; do
                        index=$((index + 1))
                        DB_DATABASE=$db php vendor/bin/phpunit -c "$OUT/phpunit.xdebug.xml" --do-not-cache-result --coverage-php="$OUT/batches/$name-$index.cov" "$file" >> "$OUT/batches/$name.log" 2>&1 || status=$?
                    done
                fi
                echo "LOTE fila $queue $name exit=$status $(( $(date +%s) - s ))s | $(tests_line "$OUT/batches/$name.log")" >> "$OUT/queues/progress.txt"
            done < "$OUT/queues/queue-$queue.txt"
        ) &
    done
    wait
    step "xdebug em lotes: fim, $(( $(date +%s) - start ))s | $(grep -c '^LOTE' "$OUT/queues/progress.txt") lotes, $(grep -c 'exit=0' "$OUT/queues/progress.txt") com exit 0, $(grep -oE '[0-9]+ failed' "$OUT/queues/progress.txt" | awk '{s+=$1} END {print s+0}') testes falhos"
    php -d memory_limit=-1 -r '
        require "vendor/autoload.php";
        $merged = null; $n = 0;
        foreach (glob($argv[1] . "/batches/*.cov") as $file) { $coverage = require $file; $n++; if ($merged === null) { $merged = $coverage; continue; } $merged->merge($coverage); unset($coverage); }
        if ($merged === null) { fwrite(STDERR, "nenhum .cov de lote\n"); exit(1); }
        new SebastianBergmann\CodeCoverage\Report\PHP()->process($merged, $argv[1] . "/xdebug.cov");
        echo "merge: $n lotes\n";
    ' "$OUT" > "$OUT/merge.log" 2>&1
    step "merge exit=$? | $(tail -1 "$OUT/merge.log")"
}

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
export PHP_INI_SCAN_DIR="$OUT/ini" XDEBUG_MODE=coverage LARAVEL_PARALLEL_TESTING=1
if [ "$BATCH" -gt 0 ]; then
    xdebug_in_batches "${1:-tests}"
else
    step "xdebug: início, $PROCESSES workers"
    start=$(date +%s)
    $CAFFEINATE php "$RUNNER" --parallel --processes="$PROCESSES" -c "$OUT/phpunit.xdebug.xml" --coverage-php="$OUT/xdebug.cov" "$@" > "$OUT/xdebug.log" 2>&1
    step "xdebug: exit=$? $(( $(date +%s) - start ))s | $(tests_line "$OUT/xdebug.log")"
fi
unset PHP_INI_SCAN_DIR XDEBUG_MODE LARAVEL_PARALLEL_TESTING

# 4. Comparação linha a linha e método a método.
[ -f "$OUT/xdebug.cov" ] || { step "xdebug.cov não foi gerado: ver $OUT/xdebug.log"; exit 1; }
php -d memory_limit=-1 "$SONDA/tools/compare.php" "$OUT/xdebug.cov" "$OUT/sonda.cov" -v > "$OUT/compare.txt" 2>&1
step "compare: exit=$?"
grep -E 'LINES|only|hit,|BRANCH|METHOD|SOUNDNESS' "$OUT/compare.txt" | tee -a "$OUT/summary.txt"
step "fim"
