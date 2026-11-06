#!/usr/bin/env bash
# Нагрузка на запись (POST /user/register) через k6 с подсчётом подтверждённых записей на стороне клиента.
# Стек поднят (`make bench-up`). Параметры через окружение:
#   PHASE     обязателен: имя эксперимента; итоги в docs/homework-replication/results/<PHASE>/
#   APP       native или symfony (по умолчанию native)
#   VUS       16, DURATION 30s, TIMEOUT 10s, PROMETHEUS_RW=1 (метрики в Prometheus)
# Итоги: write-summary.json (k6) и acked.log (по строке на подтверждённую запись, только id).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:?укажите PHASE, например PHASE=write-async}"
app="${APP:-native}"
vus="${VUS:-16}"
duration="${DURATION:-30s}"
timeout="${TIMEOUT:-10s}"
results="${root}/docs/homework-replication/results/${phase}"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")
[ -n "${ports[$app]:-}" ] || { echo "Неизвестное приложение: ${app} (native или symfony)" >&2; exit 1; }

mkdir -p "$results"
rm -f "${results}/acked.log"

# PROMETHEUS_RW=1 отправляет метрики k6 в Prometheus (профиль monitoring) для дашборда эксперимента.
docker_env=()
k6_output=()
if [ "${PROMETHEUS_RW:-0}" = "1" ]; then
    docker_env=(-e "K6_PROMETHEUS_RW_SERVER_URL=http://127.0.0.1:${PROMETHEUS_PORT:-9090}/api/v1/write"
        -e "K6_PROMETHEUS_RW_TREND_STATS=p(95),p(99),avg,max" -e "K6_PROMETHEUS_RW_PUSH_INTERVAL=2s")
    k6_output=(-o experimental-prometheus-rw --tag "app=${app}" --tag "experiment=${phase//\//-}")
fi

# --log-format raw: в лог консоли попадают только сообщения «ACK <id>», без служебных полей k6.
docker run --rm --network host --user "$(id -u):$(id -g)" "${docker_env[@]}" \
    -v "${here}:/bench:ro" -v "${results}:/results" \
    grafana/k6:1.3.0 run --quiet --log-output=file=/results/acked.log --log-format raw "${k6_output[@]}" \
    -e BASE_URL="http://127.0.0.1:${ports[$app]}" -e VUS="$vus" -e DURATION="$duration" -e TIMEOUT="$timeout" \
    -e SUMMARY="/results/write-summary.json" /bench/write.js

# Оставляем только id: остальные строки лога (ошибки k6) в проверке не нужны.
sed -n 's/^ACK //p' "${results}/acked.log" > "${results}/acked.ids"
mv "${results}/acked.ids" "${results}/acked.log"
echo "Подтверждённых записей: $(wc -l < "${results}/acked.log") (id в ${results}/acked.log)"
