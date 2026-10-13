#!/bin/bash
# usage: SUBJECT=dir run.sh <label>  -- instrumented suite + report
S=${SUBJECT:-subject}
cd "$(dirname "$0")/../$S"
export COV_CHAIN=${COV_CHAIN:-} COV_AUTOLOAD=vendor/autoload.php COV_TARGET=src COV_CACHE=../out/$S-cache${COV_STRICT:+-strict}${COV_FAST:+-fast} COV_OUT=../out/$S-runs-$1
../bench.sh $1 -- --bootstrap ../proto/bootstrap.php --no-coverage
php -d memory_limit=-1 ../proto/report.php ../out/$S-runs-$1 src ../out/$S-$1.cov
