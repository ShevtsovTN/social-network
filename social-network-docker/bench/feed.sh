#!/usr/bin/env bash
# Замер ленты постов (ДЗ «Лента постов») в обоих приложениях через k6.
# Стек поднят через `make bench-up`, БД заполнена (`make seed`, `make seed-feed`), есть feed-readers.csv (`make bench-feed-readers`).
# Параметры через окружение:
#   PHASE     обязателен: имя фазы замера (например, before-indexes); итоги кладутся в
#             docs/homework-feed/results/<PHASE>/<режим>-<приложение>-c<N>.json
#   MODES     "nocache cold warm post":
#             nocache — Redis остановлен, лента собирается из БД на каждый запрос (чтение с реплики);
#             cold    — кэш сброшен, каждый запрос от нового читателя: промах, сборка из БД и запись в Redis;
#             warm    — лента читателя уже в Redis: попадание в кэш;
#             post    — POST /post/create: запись и асинхронный fan-out; после прогона замеряется время опустошения очереди
#   LEVELS    "1 10 50 100" (число виртуальных пользователей)
#   DURATION  30s, WARMUP 10s
#   APPS      "native symfony"
#   WARM_SIZE 5000 (сколько читателей участвует в warm; остальные из feed-readers.csv — в cold)
#   RESTART_PHP 1 (перезапуск php перед замером, см. read.sh); 0 отключает
# Если замер сделан с профилем monitoring, это нужно указать в отчёте.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:?укажите PHASE, например PHASE=before-indexes}"
modes="${MODES:-nocache cold warm post}"
levels="${LEVELS:-1 10 50 100}"
duration="${DURATION:-30s}"
warmup="${WARMUP:-10s}"
apps="${APPS:-native symfony}"
warm_size="${WARM_SIZE:-5000}"
results="${root}/docs/homework-feed/results/${phase}"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")

[ -s "${here}/feed-readers.csv" ] || { echo "Нет feed-readers.csv: выполните make bench-feed-readers." >&2; exit 1; }
readers_total="$(wc -l < "${here}/feed-readers.csv")"
[ "$readers_total" -gt $((warm_size + 3000)) ] || { echo "В feed-readers.csv ${readers_total} читателей, нужно больше ${warm_size} + 3000: увеличьте READERS." >&2; exit 1; }
for mode in $modes; do
    case "$mode" in
        nocache | cold | warm | post) ;;
        *) echo "Неизвестный режим: ${mode} (nocache, cold, warm или post)" >&2; exit 1 ;;
    esac
done

mkdir -p "$results"

compose() { (cd "$root" && docker compose -f docker-compose.yaml "$@"); }
redis_cli() { compose exec -T redis redis-cli "$@" | tr -d '\r'; }

redis_up=1
redis_wait() {
    local attempt
    for attempt in $(seq 1 60); do
        if [ "$(redis_cli PING 2>/dev/null || true)" = "PONG" ]; then
            return 0
        fi
        sleep 1
    done
    echo "Redis не поднялся за 60 с." >&2
    return 1
}
redis_stop() { compose stop redis >/dev/null; redis_up=0; }
redis_start() {
    compose start redis >/dev/null
    redis_wait
    redis_up=1
    # Воркеры падают без Redis и перезапускаются docker'ом: даём им подняться.
    sleep 15
}
trap '[ "$redis_up" = "1" ] || compose start redis >/dev/null 2>&1 || true' EXIT

# Лаг репликации перед замером должен быть нулевым: иначе чтение с реплик считается на отстающих данных.
lag="$(compose exec -T postgres sh -c 'psql -X -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT coalesce(max(pg_current_wal_lsn() - replay_lsn), 0) FROM pg_stat_replication"' | tr -d '\r')"
echo "Лаг репликации: ${lag} байт"
[ "${lag:-0}" = "0" ] || echo "ВНИМАНИЕ: лаг репликации не нулевой (make repl-status), дождитесь нуля." >&2

if [ "${RESTART_PHP:-1}" = "1" ]; then
    compose restart php-native php-symfony >/dev/null
    sleep 3
fi

# Сэмплер CPU и памяти контейнеров: раз в 5 с в docker-stats.log рядом с итогами, перед каждым прогоном в лог пишется
# метка «# <режим> <приложение> c<N>». Нужен, чтобы видеть, что упирается в лимит (php, БД, Redis или воркер).
stats_log="${results}/docker-stats.log"
: > "$stats_log"
(
    while true; do
        date +%T
        docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' \
            | grep -E 'postgres|php|haproxy|redis|worker' || true
        sleep 5
    done >> "$stats_log" 2>&1
) &
sampler_pid=$!
trap 'kill "$sampler_pid" 2>/dev/null || true; [ "$redis_up" = "1" ] || compose start redis >/dev/null 2>&1 || true' EXIT

# Счётчики узлов БД до и после каждого замера (node-stats.csv): транзакции и возвращённые строки из pg_stat_database
# на каждом узле и число соединений haproxy с репликами. Разность «после − до» показывает, какой узел обслужил нагрузку.
node_stats_csv="${results}/node-stats.csv"
echo "run,moment,node,xact_commit,tup_returned,haproxy_current_sessions" > "$node_stats_csv"

node_stats() {
    local run="$1" moment="$2" node counters haproxy_sessions metrics server
    metrics="$(curl -fsS "http://127.0.0.1:${HAPROXY_STATS_PORT:-8404}/metrics" 2>/dev/null || true)"
    for node in postgres postgres-replica-1 postgres-replica-2; do
        counters="$(compose exec -T "$node" sh -c \
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

# Счётчики Redis до и после каждого замера (redis-stats.csv): попадания и промахи (keyspace_hits и keyspace_misses, их
# считают и обращения из Lua-скриптов ленты), вытесненные ключи, память и длина очереди событий приложения.
# hit ratio = Δhits / (Δhits + Δmisses); вытеснения (Δevicted_keys) должны быть нулевыми, иначе кэш мерится не тем объёмом.
redis_stats_csv="${results}/redis-stats.csv"
echo "run,moment,keyspace_hits,keyspace_misses,evicted_keys,used_memory_bytes,stream_length" > "$redis_stats_csv"

redis_stats() {
    local run="$1" moment="$2" app="$3" stats memory hits misses evicted used length
    [ "$redis_up" = "1" ] || return 0
    stats="$(redis_cli INFO stats)"
    memory="$(redis_cli INFO memory)"
    hits="$(awk -F: '$1 == "keyspace_hits" {print $2}' <<< "$stats")"
    misses="$(awk -F: '$1 == "keyspace_misses" {print $2}' <<< "$stats")"
    evicted="$(awk -F: '$1 == "evicted_keys" {print $2}' <<< "$stats")"
    used="$(awk -F: '$1 == "used_memory" {print $2}' <<< "$memory")"
    length="$(redis_cli XLEN "${app}:feed-events" 2>/dev/null || true)"
    echo "${run},${moment},${hits},${misses},${evicted},${used},${length:-0}" >> "$redis_stats_csv"
}

k6() {
    local script="$1"
    shift
    docker run --rm --network host --user "$(id -u):$(id -g)" \
        -v "${here}:/bench:ro" -v "${results}:/results" \
        grafana/k6:1.3.0 run --quiet "$@" "/bench/${script}.js"
}

# Ждёт, пока воркер разберёт очередь событий приложения; печатает секунды ожидания. Не дольше 300 с.
queue_wait() {
    local app="$1" started elapsed
    started="$(date +%s.%N)"
    while [ "$(redis_cli XLEN "${app}:feed-events")" != "0" ]; do
        elapsed="$(awk -v a="$started" -v b="$(date +%s.%N)" 'BEGIN {printf "%.1f", b - a}')"
        if awk -v e="$elapsed" 'BEGIN {exit !(e > 300)}'; then
            echo "очередь ${app} не опустела за 300 с" >&2
            break
        fi
        sleep 0.5
    done
    awk -v a="$started" -v b="$(date +%s.%N)" 'BEGIN {printf "%.1f", b - a}'
}

# Прогрев и замер одного уровня: прогрев на том же уровне (соединения, opcache, кэши PostgreSQL), итог не сохраняется.
# $1 режим, $2 приложение, $3 число виртуальных пользователей, $4 скрипт k6, остальное — дополнительные параметры k6
# для прогрева и для замера: «-e START=...» передаётся отдельно через переменные warmup_args и measure_args.
measure() {
    local mode="$1" app="$2" vus="$3" script="$4" run base_url
    run="${mode}-${app}-c${vus}"
    base_url="http://127.0.0.1:${ports[$app]}"
    echo "${mode} ${app}: ${vus} VU, прогрев ${warmup}, замер ${duration}"
    echo "# ${mode} ${app} c${vus}" >> "$stats_log"
    # shellcheck disable=SC2086
    k6 "$script" -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$warmup" $warmup_args >/dev/null
    # Очередь после прогрева записи дожидаемся до замера: лаг и длина очереди считаются только от самого замера.
    [ "$mode" != "post" ] || queue_wait "$app" >/dev/null
    node_stats "$run" before
    redis_stats "$run" before "$app"
    # shellcheck disable=SC2086
    k6 "$script" -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$duration" -e SUMMARY="/results/${run}.json" $measure_args
    node_stats "$run" after
    redis_stats "$run" after "$app"
}

# Время, за которое воркер разбирает очередь после остановки нагрузки (post-drain.csv): backlog в конце прогона и секунды до нуля.
drain_csv="${results}/post-drain.csv"
echo "run,backlog_at_end,drain_seconds" > "$drain_csv"

drain() {
    local run="$1" app="$2" backlog elapsed
    backlog="$(redis_cli XLEN "${app}:feed-events")"
    elapsed="$(queue_wait "$app")"
    echo "${run},${backlog},${elapsed}" >> "$drain_csv"
    echo "  очередь: в конце нагрузки ${backlog} событий, разобрана за ${elapsed} с"
}

warmup_args=""
measure_args=""

for mode in $modes; do
    case "$mode" in
        nocache)
            # Redis остановлен: приложение видит CacheUnavailable и собирает ленту из БД на каждый запрос.
            redis_stop
            for app in $apps; do
                for vus in $levels; do
                    warmup_args="-e MODE=warm -e WARM_SIZE=${warm_size}"
                    measure_args="$warmup_args"
                    measure "$mode" "$app" "$vus" feed
                done
            done
            redis_start
            ;;
        cold)
            for app in $apps; do
                for vus in $levels; do
                    # Кэш сбрасывается перед каждым уровнем: все читатели снова промахи. Прогрев берёт читателей из начала среза,
                    # замер идёт дальше по списку, чтобы читатели прогрева не стали попаданиями.
                    redis_cli FLUSHALL >/dev/null
                    warmup_args="-e MODE=cold -e WARM_SIZE=${warm_size} -e START=${warm_size} -e COUNT=2500"
                    measure_args="-e MODE=cold -e WARM_SIZE=${warm_size} -e START=$((warm_size + 2500))"
                    measure "$mode" "$app" "$vus" feed
                done
            done
            ;;
        warm)
            for app in $apps; do
                # Кэш приложения заполняется целиком; сбрасывается перед заполнением, чтобы ленты двух приложений
                # не вытесняли друг друга (maxmemory Redis).
                redis_cli FLUSHALL >/dev/null
                echo "warm ${app}: заполнение кэша (${warm_size} лент)"
                k6 feed -e BASE_URL="http://127.0.0.1:${ports[$app]}" -e MODE=fill -e WARM_SIZE="$warm_size" >/dev/null
                for vus in $levels; do
                    warmup_args="-e MODE=warm -e WARM_SIZE=${warm_size}"
                    measure_args="$warmup_args"
                    measure "$mode" "$app" "$vus" feed
                done
            done
            ;;
        post)
            for app in $apps; do
                for vus in $levels; do
                    warmup_args=""
                    measure_args=""
                    measure "$mode" "$app" "$vus" post
                    drain "${mode}-${app}-c${vus}" "$app"
                done
            done
            ;;
    esac
done

echo "Готово: ${results}"
