#!/usr/bin/env bash
# Эксперимент «решардинг без даунтайма» (ДЗ «Шардинг диалогов»): под постоянной нагрузкой k6 добавляет третий воркер,
# перераспределяет шарды, выносит горячий диалог в отдельный шард и переносит его на отдельный четвёртый воркер.
# Стек в режиме bench (`make bench-up`), засеян (`make seed-dialogs`), есть dialog-users.csv (`make bench-dialog-users`),
# в кластере два воркера (повторный прогон: `make dialogs-rebuild`).
# Пока идёт нагрузка, скрипт читает только метаданные кластера: подсчёт строк в шардах сам нагружает воркеры
# и портит замер задержек, поэтому полные снимки распределения снимаются до запуска k6 и после его остановки.
# Параметры: PHASE (имя прогона, по умолчанию reshard), APP (native|symfony), SEND_RPS 400, LIST_RPS 200,
# BASELINE 60 и PAUSE 30 (секунды нагрузки до изменений и между этапами).
# Итоги: docs/homework-sharding/results/<PHASE>/ — summary.md, timeline.csv, events.csv, distribution.txt, docker-stats.log.
set -euo pipefail
export LC_ALL=C

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
phase="${PHASE:-reshard}"
app="${APP:-native}"
send_rps="${SEND_RPS:-400}"
list_rps="${LIST_RPS:-200}"
baseline="${BASELINE:-60}"
pause="${PAUSE:-30}"
results="${root}/docs/homework-sharding/results/${phase}"
run="${phase}-$(date +%s)"
k6_name="dialog-k6-$$"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")
[ -n "${ports[$app]:-}" ] || { echo "Неизвестное приложение: ${app} (native или symfony)" >&2; exit 1; }
[ -s "${here}/dialog-users.csv" ] || { echo "Нет dialog-users.csv: выполните make bench-dialog-users." >&2; exit 1; }

compose() { (cd "$root" && docker compose -f docker-compose.yaml --profile reshard "$@"); }
sql() { compose exec -T dialogs-coordinator sh -c 'psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' sh "$@"; }
value() { echo "$1" | sql -tA -f - | tr -d '\r'; }

[ "$(value "SELECT count(*) FROM pg_dist_node WHERE nodename IN ('dialogs-worker-3', 'dialogs-worker-4')")" = "0" ] \
    || { echo "Третий или четвёртый воркер уже в кластере: для повторного прогона выполните make dialogs-rebuild." >&2; exit 1; }
hot_dialog="$(value "SELECT dialog_id FROM seed_dialog_roles WHERE role = 'hot' LIMIT 1")"
[ -n "$hot_dialog" ] || { echo "Нет горячего диалога: выполните make seed-dialogs." >&2; exit 1; }

mkdir -p "$results"
rm -f "${results}/requests.log" "${results}/events.csv" "${results}/markers.txt"

event() {
    local moment
    moment="$(date +%s%3N)"
    echo "${moment},$1" >> "${results}/events.csv"
    echo "[$(date +%T)] $1"
}

distribution() {
    echo "=== $1"
    sql -f - <<'SQL'
SELECT s.nodename, count(*) AS shards, sum(c.result::bigint) AS messages, pg_size_pretty(sum(s.shard_size)) AS size
FROM citus_shards AS s
JOIN run_command_on_shards('dialog_messages', 'SELECT count(*) FROM %s') AS c USING (shardid)
GROUP BY s.nodename
ORDER BY s.nodename;
SQL
    echo "Горячий диалог:"
    sql -v hot="$hot_dialog" -f - <<'SQL'
SELECT s.shardid, s.nodename, c.result AS messages_in_shard, pg_size_pretty(s.shard_size) AS shard_size
FROM citus_shards AS s
JOIN run_command_on_shards('dialog_messages', 'SELECT count(*) FROM %s') AS c USING (shardid)
WHERE s.shardid = get_shard_id_for_distribution_column('dialog_messages', :'hot'::uuid);
SQL
}

# Только метаданные координатора: на воркеры запрос не ходит.
placement() {
    echo "=== $1"
    sql -c "SELECT nodename, count(*) AS shards FROM pg_dist_shard_placement GROUP BY nodename ORDER BY nodename"
}

stats_pid=""
cleanup() {
    [ -z "$stats_pid" ] || kill "$stats_pid" 2>/dev/null || true
    docker rm -f "$k6_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Свежие воркеры php-fpm: постоянные соединения с пересозданным координатором (make dialogs-rebuild) уже мертвы, и первый
# запрос каждого воркера ответил бы 500. php перезапускается вместе с nginx: у нового контейнера может быть другой IP,
# а nginx держит адрес апстрима, разрешённый при старте.
compose restart php-native php-symfony nginx-native nginx-symfony >/dev/null 2>&1
sleep 3
# Порт приложения обязан вести в его контейнер (Symfony отдаёт Cache-Control, native нет), см. feed.sh.
headers="$(curl -s -D - -o /dev/null "http://127.0.0.1:${ports[$app]}/routing-check" | tr -d '\r')"
grep -q '^HTTP/[0-9.]* 404' <<< "$headers" || { echo "Приложение ${app} не отвечает на порту ${ports[$app]}." >&2; exit 1; }
has_cache_control="$(grep -ci '^cache-control:' <<< "$headers" || true)"
case "$app" in
    native) [ "$has_cache_control" = "0" ] ;;
    symfony) [ "$has_cache_control" != "0" ] ;;
esac || { echo "Порт ${app} ведёт не в контейнер ${app}." >&2; exit 1; }

distribution "до решардинга" > "${results}/distribution.txt"

(
    while true; do
        date +%T
        docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' | grep -E 'dialogs|php|postgres|haproxy' || true
        sleep 5
    done
) > "${results}/docker-stats.log" 2>&1 &
stats_pid=$!

# Длительность с запасом: нагрузка останавливается сигналом после последнего этапа.
docker run -d --name "$k6_name" --network host --user "$(id -u):$(id -g)" \
    -v "${here}:/bench:ro" -v "${results}:/results" \
    grafana/k6:1.3.0 run --quiet --log-output=file=/results/requests.log --log-format raw \
    -e BASE_URL="http://127.0.0.1:${ports[$app]}" -e RUN="$run" -e DURATION=60m -e LOG=1 \
    -e SEND_RPS="$send_rps" -e LIST_RPS="$list_rps" -e SUMMARY=/results/k6.txt /bench/dialog.js >/dev/null
event load_start
sleep "$baseline"
[ "$(docker inspect -f '{{.State.Running}}' "$k6_name")" = "true" ] || { docker logs "$k6_name" >&2; echo "k6 не работает." >&2; exit 1; }

compose up -d --wait dialogs-worker-3 dialogs-worker-4 >/dev/null 2>&1
event add_node_start
value "SELECT citus_add_node('dialogs-worker-3', 5432)" >/dev/null
event add_node_end

{
    echo "=== план ребалансировки"
    sql -f - <<'SQL'
SELECT sourcename, targetname, count(*) AS shard_moves, pg_size_pretty(sum(shard_size)) AS size
FROM get_rebalance_table_shards_plan()
GROUP BY sourcename, targetname
ORDER BY sourcename, targetname;
SQL
} >> "${results}/distribution.txt"

event rebalance_start
value "SELECT citus_rebalance_start()" >/dev/null 2>&1
value "SELECT citus_rebalance_wait()" >/dev/null
event rebalance_end
{
    echo "=== фоновое задание ребалансировки"
    sql -c "SELECT state, details->'task_state_counts' AS tasks, finished_at - started_at AS took FROM citus_rebalance_status()"
} >> "${results}/distribution.txt"
placement "после ребалансировки (число шардов на узлах)" >> "${results}/distribution.txt"
sleep "$pause"

event isolate_start
hot_shard="$(value "SELECT isolate_tenant_to_new_shard('dialog_messages', '${hot_dialog}'::uuid, shard_transfer_mode => 'auto')")"
event isolate_end
sleep "$pause"
source_node="$(value "SELECT nodename FROM pg_dist_shard_placement WHERE shardid = ${hot_shard}")"
# Горячий диалог получает собственный узел: четвёртый воркер регистрируется пустым, и на него переносится один шард.
target_node="dialogs-worker-4"
event add_hot_node_start
value "SELECT citus_add_node('${target_node}', 5432)" >/dev/null
event add_hot_node_end
sleep "$pause"
echo "Горячий диалог: шард ${hot_shard}, ${source_node} -> ${target_node}"
event move_start
value "SELECT citus_move_shard_placement(${hot_shard}, '${source_node}', 5432, '${target_node}', 5432, shard_transfer_mode => 'auto')" >/dev/null
event move_end
sleep "$pause"

event load_end
docker kill --signal=INT "$k6_name" >/dev/null
docker wait "$k6_name" >/dev/null
docker logs "$k6_name" 2>&1 | tail -n 3
kill "$stats_pid" 2>/dev/null || true
stats_pid=""

distribution "после выделения горячего диалога (шард ${hot_shard} перенесён ${source_node} -> ${target_node})" >> "${results}/distribution.txt"

value "SELECT text FROM dialog_messages WHERE text LIKE 'load:${run}:%'" > "${results}/markers.txt"
docker run --rm --user "$(id -u):$(id -g)" -v "${here}:/bench:ro" -v "${results}:/results" \
    python:3.13.7-slim python -I /bench/dialog-report.py /results
