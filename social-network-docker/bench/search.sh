#!/usr/bin/env bash
# Замер GET /user/search в обоих приложениях через k6 (стек поднят через `make bench-up`, БД заполнена `make seed`).
# Параметры через окружение: PHASE (обязателен: before или after индекса), LEVELS ("1 10 100 1000"),
# DURATION (60s), WARMUP (10s), APPS ("native symfony").
# Итоги k6 в JSON: docs/homework-indexes/results/<PHASE>/<app>-c<N>.json.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:?укажите PHASE=before или PHASE=after}"
levels="${LEVELS:-1 10 100 1000}"
duration="${DURATION:-60s}"
warmup="${WARMUP:-10s}"
apps="${APPS:-native symfony}"
results="${root}/docs/homework-indexes/results/${phase}"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")

mkdir -p "$results"

k6() {
    docker run --rm --network host --user "$(id -u):$(id -g)" \
        -v "${here}:/bench:ro" -v "${results}:/results" \
        grafana/k6:1.3.0 run --quiet "$@" /bench/search.js
}

for app in $apps; do
    base_url="http://127.0.0.1:${ports[$app]}"
    for vus in $levels; do
        echo "${app}: ${vus} VU, прогрев ${warmup}, замер ${duration}"
        # Прогрев на том же уровне: соединения, opcache, кэши PostgreSQL. Итог не сохраняется.
        k6 -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$warmup" >/dev/null
        k6 -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$duration" -e SUMMARY="/results/${app}-c${vus}.json"
    done
done
