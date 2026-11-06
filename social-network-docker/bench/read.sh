#!/usr/bin/env bash
# Замер чтения (GET /user/get/{id} и GET /user/search) в обоих приложениях через k6.
# Стек поднят через `make bench-up`, БД заполнена (`make seed`), есть get-ids.csv и search-queries.csv.
# Параметры через окружение:
#   PHASE     обязателен: имя фазы замера (baseline, replicas, ...); итоги кладутся в
#             docs/homework-replication/results/<PHASE>/<сценарий>-<приложение>-c<N>.json
#   SCENARIOS "get search"
#   LEVELS    "1 10 100 1000" (число виртуальных пользователей)
#   DURATION  60s, WARMUP 10s
#   APPS      "native symfony"
#   RESTART_PHP 1 (перезапуск php перед замером, см. ниже); 0 отключает
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:?укажите PHASE, например PHASE=baseline}"
scenarios="${SCENARIOS:-get search}"
levels="${LEVELS:-1 10 100 1000}"
duration="${DURATION:-60s}"
warmup="${WARMUP:-10s}"
apps="${APPS:-native symfony}"
results="${root}/docs/homework-replication/results/${phase}"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")

mkdir -p "$results"

for scenario in $scenarios; do
    case "$scenario" in
        get) input="get-ids.csv" ;;
        search) input="search-queries.csv" ;;
        *) echo "Неизвестный сценарий: ${scenario} (get или search)" >&2; exit 1 ;;
    esac
    [ -s "${here}/${input}" ] || { echo "Нет ${input}: см. make bench-get-ids и make bench-search-queries." >&2; exit 1; }
done

# Свежие воркеры php-fpm. Соединения с БД постоянные, и haproxy выбирает реплику один раз, когда воркер
# подключается. После чужих запросов (тесты, Postman, предыдущие замеры) воркеры приложения могут оказаться
# все на одной реплике, и вся нагрузка пойдёт на неё. После перезапуска воркеры подключаются подряд,
# round-robin раскладывает их 2+2. Отключается RESTART_PHP=0.
if [ "${RESTART_PHP:-1}" = "1" ]; then
    (cd "$root" && docker compose -f docker-compose.yaml restart php-native php-symfony >/dev/null)
    sleep 3
fi

# Сэмплер CPU и памяти контейнеров: раз в 5 с в docker-stats.log рядом с итогами, перед каждым прогоном
# в лог пишется метка «# <сценарий> <приложение> c<N>». Нужен, чтобы видеть, что упирается в лимит (php или БД).
stats_log="${results}/docker-stats.log"
: > "$stats_log"
(
    while true; do
        date +%T
        docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' \
            | grep -E 'postgres|php|haproxy' || true
        sleep 5
    done >> "$stats_log" 2>&1
) &
sampler_pid=$!
trap 'kill "$sampler_pid" 2>/dev/null || true' EXIT

# Счётчики узлов до и после каждого замера (node-stats.csv): число транзакций и возвращённых строк из pg_stat_database
# на каждом узле, число соединений haproxy с каждой репликой. Разность «после − до» показывает, какой узел обслужил нагрузку.
# Без реплик (baseline) снимаются только счётчики primary, haproxy там нет.
node_stats_csv="${results}/node-stats.csv"
echo "run,moment,node,xact_commit,tup_returned,haproxy_current_sessions" > "$node_stats_csv"

node_stats() {
    local run="$1" moment="$2" node counters haproxy_sessions metrics server
    metrics="$(curl -fsS "http://127.0.0.1:${HAPROXY_STATS_PORT:-8404}/metrics" 2>/dev/null || true)"
    for node in postgres postgres-replica-1 postgres-replica-2; do
        counters="$(cd "$root" && docker compose -f docker-compose.yaml exec -T "$node" sh -c \
            'psql -X -tA -F, -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT xact_commit, tup_returned FROM pg_stat_database WHERE datname = current_database()"' 2>/dev/null || echo ',')"
        haproxy_sessions=""
        case "$node" in
            postgres-replica-1) server="replica1" ;;
            postgres-replica-2) server="replica2" ;;
            *) server="" ;;
        esac
        if [ -n "$server" ] && [ -n "$metrics" ]; then
            haproxy_sessions="$(printf '%s\n' "$metrics" | awk -v s="server=\"${server}\"" 'index($0, "haproxy_server_current_sessions{") == 1 && index($0, s) {print $2}')"
        fi
        echo "${run},${moment},${node},${counters},${haproxy_sessions}" >> "$node_stats_csv"
    done
}

k6() {
    local scenario="$1"
    shift
    docker run --rm --network host --user "$(id -u):$(id -g)" \
        -v "${here}:/bench:ro" -v "${results}:/results" \
        grafana/k6:1.3.0 run --quiet "$@" "/bench/${scenario}.js"
}

for scenario in $scenarios; do
    for app in $apps; do
        base_url="http://127.0.0.1:${ports[$app]}"
        for vus in $levels; do
            echo "${scenario} ${app}: ${vus} VU, прогрев ${warmup}, замер ${duration}"
            echo "# ${scenario} ${app} c${vus}" >> "$stats_log"
            # Прогрев на том же уровне: соединения, opcache, кэши PostgreSQL. Итог не сохраняется.
            k6 "$scenario" -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$warmup" >/dev/null
            node_stats "${scenario}-${app}-c${vus}" before
            k6 "$scenario" -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$duration" \
                -e SUMMARY="/results/${scenario}-${app}-c${vus}.json"
            node_stats "${scenario}-${app}-c${vus}" after
        done
    done
done
