#!/bin/bash
# usage: SUBJECT=dir run.sh <label>  -- instrumented suite + report
S=${SUBJECT:-subject}
cd "$(dirname "$0")/../$S"
export SONDA_CHAIN=${SONDA_CHAIN:-} SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=src SONDA_CACHE=../out/$S-cache${SONDA_STRICT:+-strict}${SONDA_FAST:+-fast} SONDA_OUT=../out/$S-runs-$1
../bench.sh $1 -- --bootstrap ../proto/bootstrap.php --no-coverage
php -d memory_limit=-1 ../proto/report.php ../out/$S-runs-$1 src ../out/$S-$1.cov
