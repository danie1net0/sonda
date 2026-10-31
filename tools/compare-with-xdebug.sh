#!/bin/bash
# Compares Sonda's coverage with Xdebug's (path coverage) on the project in the current directory.
#
# Usage, from the root of the measured project:
#   DB_DATABASE=<private database> tools/compare-with-xdebug.sh <output directory> [runner arguments...]
#
# Variables: PROCESSES (ParaTest workers or batch queues, default 3), XDEBUG_SO (path of xdebug.so)
# and BATCH (tests per batch; with it the Xdebug side runs in batches, see below; in batches only
# the tests directory, the first runner argument when it is a directory, is considered).
# Output: sonda.cov, xdebug.cov, compare.txt and summary.txt in the given directory.
#
# Xdebug with paths costs ~30x the time of the suite, and php-code-coverage accumulates, on every
# test, the test ids on every branch and path of the loaded files: in a large suite the cost per
# test grows until the run never finishes. BATCH=80 splits the test files into batches of up to 80
# tests, each in a new process, PROCESSES queues in parallel, and merges the .cov at the end with
# CodeCoverage::merge(). Each queue uses the database ${DB_DATABASE}_q<N>, created with createdb
# when available. Run on an idle machine (caffeinate keeps it from sleeping).

OUT=${1:?give the output directory}
shift
ROOT=$(pwd)
SONDA=$(cd "$(dirname "$0")/.." && pwd)
PROCESSES=${PROCESSES:-3}
BATCH=${BATCH:-0}
XDEBUG_SO=${XDEBUG_SO:-/opt/homebrew/opt/xdebug@8.5/xdebug.so}
CAFFEINATE=$(command -v caffeinate >/dev/null && echo "caffeinate -i" || echo "")

[ -f "$ROOT/phpunit.xml" ] || { echo "Rode na raiz de um projeto com phpunit.xml." >&2; exit 1; }
[ -f "$ROOT/vendor/autoload.php" ] || { echo "Falta vendor/autoload.php: composer install." >&2; exit 1; }
[ -f "$XDEBUG_SO" ] || { echo "Xdebug not found at $XDEBUG_SO (XDEBUG_SO=...)." >&2; exit 1; }
RUNNER=vendor/bin/pest; [ -x "$RUNNER" ] || RUNNER=vendor/bin/paratest
[ -x "$RUNNER" ] || { echo "Falta vendor/bin/pest ou vendor/bin/paratest." >&2; exit 1; }

mkdir -p "$OUT/ini"
OUT=$(cd "$OUT" && pwd)
printf 'zend_extension=%s\nxdebug.mode=coverage\nmemory_limit=-1\n' "$XDEBUG_SO" > "$OUT/ini/xdebug.ini"

step() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$OUT/summary.txt"; }
tests_line() { sed 's/\x1b\[[0-9;]*m//g' "$1" | grep -E 'Tests:' | tail -1 | sed 's/^ *//'; }

# Batches of up to $BATCH tests (whole test files), spread by count over $PROCESSES queues; each
# batch is a new process, each queue has its own database; the .cov files are merged at the end.
xdebug_in_batches() {
    local tests_root=$1
    mkdir -p "$OUT/batches" "$OUT/queues"
    rm -f "$OUT/batches"/* "$OUT/queues"/*
    for f in $(find "$tests_root" -name '*Test.php' | sort); do
        # Pest tests (it, test, arch) or PHPUnit test* methods; at least one per file.
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
    step "xdebug in batches: start, $total batches of up to $BATCH tests in $PROCESSES queues"
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
                # Pest takes several paths; PHPUnit, one only: then each file of the batch runs alone.
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
step "project $ROOT, tree: $(git -C "$ROOT" status --short 2>/dev/null | wc -l | tr -d ' ') modified file(s)"

# 1. Sonda, with the <source> of the project's phpunit.xml.
step "sonda: start"
start=$(date +%s)
$CAFFEINATE "$SONDA/bin/sonda" test --cov="$OUT/sonda.cov" "$@" > "$OUT/sonda.log" 2>&1
step "sonda: exit=$? $(( $(date +%s) - start ))s | $(tests_line "$OUT/sonda.log") | $(sed 's/\x1b\[[0-9;]*m//g' "$OUT/sonda.log" | grep -E '^Cobertura' | tail -1)"

# 2. phpunit.xml with absolute paths and pathCoverage on: --path-coverage does not go through ParaTest,
#    and a config outside the root resolves relative paths from its own directory.
sed -e "s#bootstrap=\"\([^/\"][^\"]*\)\"#bootstrap=\"$ROOT/\1\"#" \
    -e "s#\(<directory[^>]*>\)\([^/]\)#\1$ROOT/\2#" \
    -e "s#\(<file[^>]*>\)\([^/]\)#\1$ROOT/\2#" \
    "$ROOT/phpunit.xml" > "$OUT/phpunit.xdebug.xml"
if ! grep -q '<coverage' "$OUT/phpunit.xdebug.xml"; then
    sed 's#</source>#</source>\n  <coverage pathCoverage="true"/>#' "$OUT/phpunit.xdebug.xml" > "$OUT/phpunit.tmp" && mv "$OUT/phpunit.tmp" "$OUT/phpunit.xdebug.xml"
elif ! grep -q 'pathCoverage=' "$OUT/phpunit.xdebug.xml"; then
    sed 's#<coverage#<coverage pathCoverage="true"#' "$OUT/phpunit.xdebug.xml" > "$OUT/phpunit.tmp" && mv "$OUT/phpunit.tmp" "$OUT/phpunit.xdebug.xml"
fi

# 3. Xdebug in the workers via PHP_INI_SCAN_DIR (the -d option does not reach child processes). Only
#    this script's directory is read, without the default conf.d: an Xdebug already loaded there would
#    be loaded twice, and PCOV, when present, would compete with it for the coverage.
export PHP_INI_SCAN_DIR="$OUT/ini" XDEBUG_MODE=coverage LARAVEL_PARALLEL_TESTING=1
if [ "$BATCH" -gt 0 ]; then
    # The tests directory is the first runner argument when it is a directory; the others do not reach the batches.
    tests_root=tests; [ $# -gt 0 ] && [ -d "$1" ] && tests_root=$1
    xdebug_in_batches "$tests_root"
else
    step "xdebug: start, $PROCESSES workers"
    start=$(date +%s)
    $CAFFEINATE php "$RUNNER" --parallel --processes="$PROCESSES" -c "$OUT/phpunit.xdebug.xml" --coverage-php="$OUT/xdebug.cov" "$@" > "$OUT/xdebug.log" 2>&1
    step "xdebug: exit=$? $(( $(date +%s) - start ))s | $(tests_line "$OUT/xdebug.log")"
fi
unset PHP_INI_SCAN_DIR XDEBUG_MODE LARAVEL_PARALLEL_TESTING

# 4. Comparison line by line and method by method.
[ -f "$OUT/xdebug.cov" ] || { step "xdebug.cov was not written: see $OUT/xdebug.log"; exit 1; }
php -d memory_limit=-1 "$SONDA/tools/compare.php" "$OUT/xdebug.cov" "$OUT/sonda.cov" -v > "$OUT/compare.txt" 2>&1
step "compare: exit=$?"
grep -E 'LINES|only|hit,|BRANCH|METHOD|SOUNDNESS' "$OUT/compare.txt" | tee -a "$OUT/summary.txt"
step "fim"
