#!/usr/bin/env bash
# 配布物 zip（HEAD の git archive。設定・データ・outputs は .gitignore で入らない）。  bash scripts/build_package.sh <出力先.zip>
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:?出力先の zip を渡してください}"
php scripts/check.php >/dev/null
git archive --format=zip --prefix=kchinjomeibo/ -o "$OUT" HEAD
unzip -l "$OUT" | tail -1; echo "$OUT"
