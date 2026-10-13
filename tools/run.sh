#!/bin/bash
# usage: SUBJECT=dir run.sh <label>  -- instrumented suite + report
S=${SUBJECT:-subject}
cd "$(dirname "$0")/../$S"
export COV_CHAIN=${COV_CHAIN:-} COV_AUTOLOAD=vendor/autoload.php COV_TARGET=src COV_CACHE=../out/$S-cache${COV_STRICT:+-strict}${COV_FAST:+-fast} COV_OUT=../out/$S-hits-$1.ser
../bench.sh $1 -- --bootstrap ../proto/bootstrap.php --extension 'CovProto\PhpUnitExtension' --no-coverage
php -d memory_limit=-1 ../proto/report.php ../out/$S-hits-$1.ser src ../out/$S-$1.cov
