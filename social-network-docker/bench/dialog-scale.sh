#!/usr/bin/env bash
# Растёт ли пропускная способность записи с числом воркеров (ДЗ «Шардинг диалогов»). Нагрузку даёт pgbench из отдельного
# контейнера прямо на координатор, в обход PHP: через приложение запись упирается в пул php-fpm раньше, чем в кластер.
# Замер идёт на отдельном одноразовом кластере (свой проект compose и свои тома, после замера удаляется), поэтому рабочий
# кластер и его данные не затрагиваются. Для каждого числа воркеров и каждого лимита CPU координатора — REPEATS прогонов
# на пустой таблице после прогрева.
# Параметры: WORKERS "2 3 4", COORDINATOR_CPUS "1 4", CLIENTS 64, DURATION 30, WARMUP 10 (секунды), REPEATS 2,
# DIALOGS_SCALE_PORT 15440. Лимиты воркеров — как в compose (DIALOGS_WORKER_CPUS, DIALOGS_WORKER_MEMORY).
# Итоги: docs/homework-sharding/results/write-scaling/ — summary.md и runs.csv.
set -euo pipefail
export LC_ALL=C

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
worker_counts="${WORKERS:-2 3 4}"
coordinator_cpus="${COORDINATOR_CPUS:-1 4}"
clients="${CLIENTS:-64}"
duration="${DURATION:-30}"
warmup="${WARMUP:-10}"
repeats="${REPEATS:-2}"
results="${root}/docs/homework-sharding/results/write-scaling"
project="social-network-scale"
export DIALOGS_POSTGRES_PORT="${DIALOGS_SCALE_PORT:-15440}"

compose() { (cd "$root" && docker compose -p "$project" -f docker-compose.yaml --profile reshard "$@"); }
sql() { compose exec -T dialogs-coordinator sh -c 'psql -X -q -tA -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -'; }
# pgbench запускается из образа сервиса dialogs-init: в его окружении уже есть параметры подключения к координатору.
pgbench() {
    compose run --rm --no-deps -T -v "${here}:/bench:ro" --entrypoint pgbench dialogs-init \
        -n -c "$clients" -j 8 -f /bench/dialog-scale.sql -T "$1" 2>&1
}

stats_file="$(mktemp)"
sampler_pid=""
cleanup() {
    [ -z "$sampler_pid" ] || kill "$sampler_pid" 2>/dev/null || true
    rm -f "$stats_file"
    echo "Удаляю одноразовый кластер ${project}"
    compose down -v >/dev/null 2>&1 || echo "Не удалось удалить кластер: docker compose -p ${project} --profile reshard down -v" >&2
}
trap cleanup EXIT

mkdir -p "$results"
echo "workers,coordinator_cpus,repeat,tps,latency_ms,failed,coordinator_cpu_percent,worker_cpu_percent_avg" > "${results}/runs.csv"

compose up -d dialogs-coordinator dialogs-worker-1 dialogs-worker-2 dialogs-init >/dev/null 2>&1
# docker wait, а не compose wait: тот завершается ошибкой, если контейнер уже успел отработать.
[ "$(docker wait "$(compose ps -aq dialogs-init)")" = "0" ] \
    || { compose logs dialogs-init >&2; echo "Кластер диалогов не инициализировался." >&2; exit 1; }
registered=2

for workers in $worker_counts; do
    while [ "$registered" -lt "$workers" ]; do
        registered=$((registered + 1))
        compose up -d --wait "dialogs-worker-${registered}" >/dev/null 2>&1
        echo "SELECT citus_add_node('dialogs-worker-${registered}', 5432)" | sql >/dev/null
    done
    # На пустой таблице шарды ничего не весят: распределяем их по числу, поровну между воркерами.
    echo "TRUNCATE dialog_messages" | sql >/dev/null
    echo "SELECT citus_rebalance_start(rebalance_strategy => 'by_shard_count'); SELECT citus_rebalance_wait();" | sql >/dev/null 2>&1 || true
    shards="$(echo "SELECT string_agg(shards::text, '/' ORDER BY nodename) FROM (SELECT nodename, count(*) AS shards FROM pg_dist_shard_placement GROUP BY nodename) AS s" | sql)"
    echo "Воркеров: ${workers}, шардов на них: ${shards}"

    for cpus in $coordinator_cpus; do
        docker update --cpus "$cpus" "$(compose ps -q dialogs-coordinator)" >/dev/null
        for repeat in $(seq 1 "$repeats"); do
            echo "TRUNCATE dialog_messages; CHECKPOINT;" | sql >/dev/null
            pgbench "$warmup" >/dev/null
            : > "$stats_file"
            (
                sleep 5
                while true; do
                    docker stats --no-stream --format '{{.Name}} {{.CPUPerc}}' | grep "^${project}-dialogs-" >> "$stats_file" || true
                    sleep 2
                done
            ) &
            sampler_pid=$!
            output="$(pgbench "$duration")"
            kill "$sampler_pid" 2>/dev/null || true
            wait "$sampler_pid" 2>/dev/null || true
            sampler_pid=""
            tps="$(awk '/^tps = /{print $3}' <<< "$output")"
            latency="$(awk '/^latency average = /{print $4}' <<< "$output")"
            failed="$(awk '/^number of failed transactions:/{print $5}' <<< "$output")"
            [ -n "$tps" ] || { echo "$output" >&2; exit 1; }
            coordinator_cpu="$(awk '/coordinator/{sub("%", "", $2); s += $2; n++} END {printf "%.0f", n ? s / n : 0}' "$stats_file")"
            worker_cpu="$(awk '/worker/{sub("%", "", $2); s += $2; n++} END {printf "%.0f", n ? s / n : 0}' "$stats_file")"
            echo "${workers},${cpus},${repeat},${tps},${latency},${failed:-0},${coordinator_cpu},${worker_cpu}" >> "${results}/runs.csv"
            echo "  координатор ${cpus} CPU, прогон ${repeat}: ${tps} вставок/с, ${latency} мс, ошибок ${failed:-0}, CPU координатора ${coordinator_cpu}%, воркера ${worker_cpu}%"
        done
    done
done

worker_limit="$(docker inspect -f '{{.HostConfig.NanoCpus}}' "$(compose ps -q dialogs-worker-1)" | awk '{printf "%g", $1 / 1e9}')"
{
    echo "Вставки одного сообщения pgbench'ем прямо на координатор: ${clients} клиентов, ${duration} с после прогрева ${warmup} с,"
    echo "таблица перед каждым прогоном пустая. Лимит каждого воркера ${worker_limit} CPU. CPU в процентах одного ядра (docker stats)."
    echo
    echo "| CPU координатора | Воркеров | Вставок/с (среднее) | Прогоны | К двум воркерам | Задержка, мс | CPU координатора, % | CPU воркера, % | Ошибок |"
    echo "|---|---|---|---|---|---|---|---|---|"
    for cpus in $coordinator_cpus; do
        base=""
        for workers in $worker_counts; do
            line="$(awk -F, -v w="$workers" -v c="$cpus" '$1 == w && $2 == c {tps += $4; lat += $5; failed += $6; cc += $7; wc += $8; n++; runs = runs (runs ? ", " : "") sprintf("%.0f", $4)}
                END {printf "%.0f|%s|%.1f|%.0f|%.0f|%d", tps / n, runs, lat / n, cc / n, wc / n, failed}' "${results}/runs.csv")"
            IFS='|' read -r mean runs latency coordinator_cpu worker_cpu failed <<< "$line"
            [ -n "$base" ] || base="$mean"
            ratio="$(awk -v a="$mean" -v b="$base" 'BEGIN {printf "%.2f", a / b}')"
            echo "| ${cpus} | ${workers} | ${mean} | ${runs} | ×${ratio} | ${latency} | ${coordinator_cpu} | ${worker_cpu} | ${failed} |"
        done
    done
} | tee "${results}/summary.md"
