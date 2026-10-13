#!/bin/bash
# usage: SUBJECT=dir bench.sh <label> <php args...> -- <phpunit args...>
S=${SUBJECT:-subject}
cd "$(dirname "$0")/$S"
label=$1; shift
phpargs=(); while [ "$1" != "--" ]; do phpargs+=("$1"); shift; done; shift
extra=(); [ "$S" = subject ] && extra=(--exclude-filter testBytePositionMapHasBoundedMemoryOverhead)
/usr/bin/time -l php -d memory_limit=-1 "${phpargs[@]}" vendor/bin/phpunit --no-progress "${extra[@]}" "$@" > ../out/$S-run-$label.txt 2> ../out/$S-time-$label.txt
grep -E '^(OK|Tests:|.*Tests: )' ../out/$S-run-$label.txt | tail -1
awk '/ real /{printf "%s real=%ss user=%ss sys=%ss ", "'$S/$label'", $1, $3, $5} /maximum resident/{printf "rss=%dMB\n", $1/1048576}' ../out/$S-time-$label.txt
