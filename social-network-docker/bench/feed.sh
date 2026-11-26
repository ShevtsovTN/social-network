#!/usr/bin/env bash
# Замер ленты постов (ДЗ «Лента постов») в обоих приложениях через k6.
# Стек поднят через `make bench-up`, БД заполнена (`make seed`, `make seed-feed`), есть feed-readers.csv (`make bench-feed-readers`).
# Параметры через окружение:
#   PHASE     обязателен: имя фазы замера (например, before-indexes); итоги кладутся в
#             docs/homework-feed/results/<PHASE>/<режим>-<приложение>-c<N>.json
#   MODES     "nocache cold warm post":
#             nocache — Redis недоступен (заблокирован сменой пароля), лента собирается из БД на каждый запрос (чтение с реплики);
#             cold    — кэш сброшен, каждый запрос от нового читателя: промах, сборка из БД и запись в Redis;
#             warm    — лента читателя уже в Redis: попадание в кэш;
#             post    — POST /post/create: запись и асинхронный fan-out; после прогона замеряется время опустошения очереди
#   LEVELS    "1 10 50 100" (число виртуальных пользователей)
#   DURATION  30s, WARMUP 10s
#   APPS      "native symfony"
#   WARM_SIZE 2000 (сколько читателей участвует в warm; остальные из feed-readers.csv — в cold). Заполнение кэша — это столько же
#             холодных сборок ленты, без индекса posts(author_id, ...) каждая около полусекунды, поэтому значение держим умеренным
#   DRAIN_TIMEOUT 120 (секунд ждать, пока воркер разберёт очередь событий; потом очередь обрезается, замер идёт дальше)
#   RESTART_PHP 1 (перезапуск php вместе с nginx перед замером, см. read.sh); 0 отключает
# Если замер сделан с профилем monitoring, это нужно указать в отчёте.
set -euo pipefail

# Числа в awk и сравнения строк не должны зависеть от локали (в ru_RU awk печатает 4,2 вместо 4.2, и CSV ломается).
export LC_ALL=C

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:?укажите PHASE, например PHASE=before-indexes}"
modes="${MODES:-nocache cold warm post}"
levels="${LEVELS:-1 10 50 100}"
duration="${DURATION:-30s}"
warmup="${WARMUP:-10s}"
apps="${APPS:-native symfony}"
warm_size="${WARM_SIZE:-2000}"
drain_timeout="${DRAIN_TIMEOUT:-120}"
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
# «Недоступный» Redis в режиме nocache: контейнер не останавливаем. У остановленного контейнера имя не разрешается, а у php
# короткий таймаут резолвера (dns_opt timeout:1), так что каждый запрос ждал бы около секунды ещё до обращения к БД, и замер
# показывал бы таймаут DNS, а не сборку ленты. Вместо этого меняем пароль и обрываем клиентов: подключение к Redis отвечает
# отказом аутентификации сразу, кэш превращает его в CacheUnavailable, и лента читается из БД с реплики.
locked_password="bench-locked-$$"
redis_stop() {
    compose exec -T redis redis-cli CONFIG SET requirepass "$locked_password" >/dev/null
    compose exec -T redis redis-cli CLIENT KILL TYPE normal >/dev/null 2>&1 || true
    redis_up=0
}
redis_unlock() {
    # Контейнер знает исходный пароль (REDISCLI_AUTH); под заблокированным паролем входим переопределённым значением.
    compose exec -T -e LOCKED="$locked_password" redis sh -c \
        'REDISCLI_AUTH="$LOCKED" redis-cli CONFIG SET requirepass "$REDISCLI_AUTH" >/dev/null 2>&1 || true' || true
}
redis_start() {
    redis_unlock
    redis_wait
    redis_up=1
    # Воркеры падают без доступа к Redis и перезапускаются docker'ом: даём им подняться.
    sleep 15
}
trap '[ "$redis_up" = "1" ] || redis_unlock' EXIT

# Лаг репликации перед замером должен быть нулевым: иначе чтение с реплик считается на отстающих данных.
lag="$(compose exec -T postgres sh -c 'psql -X -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT coalesce(max(pg_current_wal_lsn() - replay_lsn), 0) FROM pg_stat_replication"' | tr -d '\r')"
echo "Лаг репликации: ${lag} байт"
[ "${lag:-0}" = "0" ] || echo "ВНИМАНИЕ: лаг репликации не нулевой (make repl-status), дождитесь нуля." >&2

# Свежие воркеры php-fpm. php перезапускается ВМЕСТЕ с nginx: у нового контейнера php может оказаться другой IP (при одновременном
# перезапуске двух контейнеров они меняются адресами), а nginx держит адрес апстрима, разрешённый при старте. Без перезапуска nginx
# порт одного приложения уходит в контейнер другого, и подписи «native» и «symfony» в результатах путаются.
if [ "${RESTART_PHP:-1}" = "1" ]; then
    compose restart php-native php-symfony nginx-native nginx-symfony >/dev/null
    sleep 3
fi

# Порт приложения обязан вести в его контейнер. Различаем по заголовку: Symfony отдаёт Cache-Control, native нет.
# Запрос идёт на несуществующий путь и не обращается к БД: запрос с чтением открыл бы по одному постоянному соединению
# через haproxy до начала нагрузки и перекосил бы распределение воркеров по репликам (3:1 вместо 2:2).
check_routing() {
    local app="$1" headers has_cache_control
    headers="$(curl -s -D - -o /dev/null "http://127.0.0.1:${ports[$app]}/routing-check" | tr -d '\r')"
    grep -q '^HTTP/[0-9.]* 404' <<< "$headers" || { echo "Приложение ${app} не отвечает на порту ${ports[$app]}." >&2; exit 1; }
    has_cache_control="$(grep -ci '^cache-control:' <<< "$headers" || true)"
    case "$app" in
        native) [ "$has_cache_control" = "0" ] ;;
        symfony) [ "$has_cache_control" != "0" ] ;;
    esac || { echo "Порт ${app} ведёт не в контейнер ${app}: перезапустите nginx вместе с php (docker compose restart nginx-native nginx-symfony)." >&2; exit 1; }
}
for app in $apps; do
    check_routing "$app"
done

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
trap 'kill "$sampler_pid" 2>/dev/null || true; [ "$redis_up" = "1" ] || redis_unlock' EXIT

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

# Ждёт, пока воркер разберёт очередь событий приложения, не дольше $drain_timeout секунд. Печатает через пробел секунды ожидания
# и сколько событий осталось (0 — очередь пуста).
queue_wait() {
    local app="$1" started now elapsed remaining
    started="$(date +%s)"
    while :; do
        remaining="$(redis_cli XLEN "${app}:feed-events")"
        elapsed=$(( $(date +%s) - started ))
        if [ "${remaining:-0}" = "0" ] || [ "$elapsed" -ge "$drain_timeout" ]; then
            break
        fi
        sleep 0.5
    done
    now="$(date +%s.%N)"
    awk -v a="$started" -v b="$now" -v r="${remaining:-0}" 'BEGIN {printf "%.1f %d", b - a, r}'
}

# Перед замером очередь должна быть пустой: хвост событий от прошлых прогонов грузит БД (fan-out каждого поста сканирует
# friendships) и искажает чтение. Не разобралась за $drain_timeout секунд — обрезаем (события теряются, ленты это переживают).
settle_queue() {
    local app="$1" result remaining
    [ "$redis_up" = "1" ] || return 0
    result="$(queue_wait "$app")"
    remaining="${result#* }"
    if [ "$remaining" != "0" ]; then
        echo "  очередь ${app} не разобрана за ${drain_timeout} с (осталось ${remaining}), обрезается" >&2
        redis_cli XTRIM "${app}:feed-events" MAXLEN 0 >/dev/null
    fi
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
    [ "$mode" != "post" ] || settle_queue "$app"
    node_stats "$run" before
    redis_stats "$run" before "$app"
    # shellcheck disable=SC2086
    k6 "$script" -e BASE_URL="$base_url" -e VUS="$vus" -e DURATION="$duration" -e SUMMARY="/results/${run}.json" $measure_args
    node_stats "$run" after
    redis_stats "$run" after "$app"
}

# Разбор очереди воркером после остановки нагрузки (post-drain.csv): сколько событий накопилось к концу замера, сколько осталось
# через drain_seconds и скорость разбора. Не разобралась за DRAIN_TIMEOUT — remaining больше нуля, скорость считается по ней.
drain_csv="${results}/post-drain.csv"
echo "run,backlog_at_end,remaining,drain_seconds,events_per_second" > "$drain_csv"

drain() {
    local run="$1" app="$2" backlog result seconds remaining rate
    backlog="$(redis_cli XLEN "${app}:feed-events")"
    result="$(queue_wait "$app")"
    seconds="${result% *}"
    remaining="${result#* }"
    rate="$(awk -v b="$backlog" -v r="$remaining" -v s="$seconds" 'BEGIN {if (s > 0) printf "%.1f", (b - r) / s; else print "0.0"}')"
    echo "${run},${backlog},${remaining},${seconds},${rate}" >> "$drain_csv"
    echo "  очередь: к концу нагрузки ${backlog} событий, через ${seconds} с осталось ${remaining}, воркер разбирает ${rate} событий/с"
    [ "$remaining" = "0" ] || redis_cli XTRIM "${app}:feed-events" MAXLEN 0 >/dev/null
}

# Хвост очереди от прошлых прогонов не должен греть БД во время замера.
for app in $apps; do
    settle_queue "$app"
done

warmup_args=""
measure_args=""

for mode in $modes; do
    case "$mode" in
        nocache)
            # Redis заблокирован: приложение видит CacheUnavailable и собирает ленту из БД на каждый запрос.
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
                filled="$(k6 feed -e BASE_URL="http://127.0.0.1:${ports[$app]}" -e MODE=fill -e WARM_SIZE="$warm_size")"
                echo "  ${filled}"
                # Заполнены должны быть все читатели: недозаполненный кэш превращает часть запросов warm в промахи.
                [[ "$filled" == *"requests=${warm_size} "* && "$filled" == *"failed=0.00%"* ]] \
                    || { echo "Заполнение кэша не завершилось штатно (ожидалось ${warm_size} запросов без ошибок)." >&2; exit 1; }
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
