#!/usr/bin/env bash
# Эксперимент с аварийной остановкой мастера (ДЗ «Репликация»). Режим: async или sync (кворум ANY 1).
#
# Ход (порядок из задания):
#   1. Включается режим репликации, на primary добавляется задержка сети в сторону реплик (netem): без неё на localhost
#      лаг около миллисекунды и в async-режиме терять нечего. Задержка делает отставание реплик заметным.
#   2. Запускается нагрузка на запись (POST /user/register в оба приложения). Клиент считает подтверждённые записи (ACK)
#      и пишет их id в файл. Параллельно идёт проба чтения GET /user/get/{id} через приложение.
#   3. Через KILL_AFTER секунд primary убивается: docker kill -s KILL (SIGKILL, аналог kill -9).
#   4. Нагрузка на запись доходит до конца и останавливается.
#   5. Выбирается самая свежая реплика (по pg_last_wal_receive_lsn), она повышается до мастера (pg_promote),
#      вторая реплика переключается на неё (primary_conninfo), приложения перенаправляются на нового мастера.
#   6. Проверка потерь: каждый подтверждённый клиенту id ищется на новом мастере.
#
# Параметры через окружение: NETEM_DELAY (300ms), VUS (на каждое приложение, 8), DURATION (секунд, 40),
# KILL_AFTER (секунд до kill, 15). Перед запуском кластер должен быть чистым: make repl-rebuild.
# Результаты: docs/homework-replication/results/failover-<режим>/ (summary.md, acked ids, лог пробы чтения).
set -euo pipefail

mode="${1:?укажите режим: async или sync}"
case "$mode" in async | sync) ;; *) echo "Режим: async или sync" >&2; exit 1 ;; esac

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
cd "$root"

delay="${NETEM_DELAY:-300ms}"
vus="${VUS:-8}"
duration="${DURATION:-40}"
kill_after="${KILL_AFTER:-15}"
phase="failover-${mode}"
results="${root}/docs/homework-replication/results/${phase}"
native_port="${NATIVE_PORT:-8081}"
symfony_port="${SYMFONY_PORT:-8082}"
grafana_port="${GRAFANA_PORT:-3000}"

export PHP_BUILD_TARGET=bench
unset DB_PRIMARY_HOST
compose() { docker compose -f docker-compose.yaml "$@"; }
now() { date +%s.%N; }
log() { printf '\n=== %s\n' "$*"; }

# SQL на узле (результат без выравнивания). Аргументы после имени узла: параметры psql.
sql() {
    local node="$1"
    shift
    compose exec -T "$node" sh -c 'psql -X -q -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' -- "$@"
}

grafana_password="$(grep -E '^GRAFANA_ADMIN_PASSWORD=' .env 2>/dev/null | cut -d= -f2- || true)"
if curl -fsS -m 2 "http://127.0.0.1:${grafana_port}/api/health" >/dev/null 2>&1 && [ -n "$grafana_password" ]; then
    grafana_ok=1
else
    grafana_ok=0
fi
# Отметка на дашбордах Grafana (если мониторинг поднят): kill, promote и т. д.
annotate() {
    [ "$grafana_ok" = 1 ] || return 0
    curl -fsS -m 3 -u "admin:${grafana_password}" -H 'Content-Type: application/json' \
        -d "{\"time\":$(date +%s%3N),\"tags\":[\"experiment\",\"${mode}\"],\"text\":\"$1\"}" \
        "http://127.0.0.1:${grafana_port}/api/annotations" >/dev/null 2>&1 || true
}

# --- 0. Проверка исходного состояния ---
log "Проверка кластера"
in_recovery="$(sql postgres -c 'SELECT pg_is_in_recovery()' 2>/dev/null || echo unavailable)"
[ "$in_recovery" = "f" ] || { echo "postgres не primary или недоступен (${in_recovery}): выполните make repl-rebuild" >&2; exit 1; }
streaming="$(sql postgres -c "SELECT count(*) FROM pg_stat_replication WHERE state = 'streaming'")"
[ "$streaming" = "2" ] || { echo "Подключено реплик: ${streaming}, нужно 2: выполните make repl-rebuild" >&2; exit 1; }

rm -rf "$results"
mkdir -p "$results"

# Анкета для пробы чтения: любая существующая до начала нагрузки.
probe_id="$(sql postgres -c "SELECT id FROM users ORDER BY id LIMIT 1")"
[ -n "$probe_id" ] || { echo "В БД нет анкет для пробы чтения: make seed" >&2; exit 1; }

# --- 1. Режим репликации и задержка сети ---
log "Режим репликации: ${mode}"
"${here}/sync-mode.sh" "$mode"

replica_ips=()
for node in postgres-replica-1 postgres-replica-2; do
    replica_ips+=("$(docker inspect -f '{{(index .NetworkSettings.Networks "social-network_backend").IPAddress}}' "$(compose ps -q "$node")")")
done
log "Задержка сети primary → реплики: ${delay}"
"${here}/netem.sh" add postgres "$delay" "${replica_ips[@]}"

t_start="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# --- 2. Нагрузка на запись и проба чтения ---
log "Нагрузка на запись: ${vus} VU на каждое приложение, ${duration} с; kill через ${kill_after} с"
annotate "Старт нагрузки на запись (${mode}, задержка ${delay})"
load_pids=()
for app in native symfony; do
    PROMETHEUS_RW="$grafana_ok" PHASE="${phase}/${app}" APP="$app" VUS="$vus" DURATION="${duration}s" TIMEOUT=5s \
        "${root}/social-network-docker/bench/write.sh" > "${results}/${app}.k6.out" 2>&1 &
    load_pids+=($!)
done

(
    while :; do
        status="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "http://127.0.0.1:${symfony_port}/user/get/${probe_id}" || true)"
        echo "$(now) ${status:-000}"
        sleep 0.5
    done
) > "${results}/read-probe.log" &
probe_pid=$!
trap 'kill "$probe_pid" 2>/dev/null || true' EXIT

sleep "$kill_after"

# --- 3. Авария ---
log "Состояние репликации за секунду до kill"
sql postgres -F ' ' -c "SELECT application_name, sync_state, pg_wal_lsn_diff(pg_current_wal_lsn(), flush_lsn) AS not_flushed_bytes, pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS not_replayed_bytes FROM pg_stat_replication ORDER BY 1" \
    | tee "${results}/lag-before-kill.txt"
primary_container="$(compose ps -q postgres)"
log "kill -9 primary (docker kill -s KILL)"
annotate "kill -9 primary"
t_kill="$(now)"
docker kill -s KILL "$primary_container" >/dev/null

# --- 4. Остановка нагрузки на запись ---
log "Жду окончания нагрузки на запись"
for pid in "${load_pids[@]}"; do wait "$pid" || true; done
t_load_end="$(now)"
annotate "Нагрузка на запись остановлена"
for app in native symfony; do echo "${app}: $(tail -n 2 "${results}/${app}.k6.out" | head -n 1)"; done

# --- 5. Самая свежая реплика, promote, переключение ---
log "Выбор самой свежей реплики"
receive_1="$(sql postgres-replica-1 -c 'SELECT pg_last_wal_receive_lsn()')"
receive_2="$(sql postgres-replica-2 -c 'SELECT pg_last_wal_receive_lsn()')"
replay_1="$(sql postgres-replica-1 -c 'SELECT pg_last_wal_replay_lsn()')"
replay_2="$(sql postgres-replica-2 -c 'SELECT pg_last_wal_replay_lsn()')"
diff_bytes="$(sql postgres-replica-1 -c "SELECT pg_wal_lsn_diff('${receive_1}', '${receive_2}')")"
if [ "${diff_bytes%.*}" -ge 0 ] 2>/dev/null; then
    new_primary="postgres-replica-1"; other="postgres-replica-2"; new_name="replica1"; other_name="replica2"
else
    new_primary="postgres-replica-2"; other="postgres-replica-1"; new_name="replica2"; other_name="replica1"
fi
{
    echo "replica1: received=${receive_1} replayed=${replay_1}"
    echo "replica2: received=${receive_2} replayed=${replay_2}"
    echo "received(replica1) - received(replica2) = ${diff_bytes} байт"
    echo "Самая свежая: ${new_name} (${new_primary})"
} | tee "${results}/freshest.txt"

log "Promote ${new_primary}"
t_promote="$(now)"
sql "$new_primary" -c 'SELECT pg_promote(true, 60)' >/dev/null
t_promoted="$(now)"
annotate "promote ${new_name}"
echo "pg_is_in_recovery: $(sql "$new_primary" -c 'SELECT pg_is_in_recovery()')"

log "Переключаю ${other_name} на нового мастера"
printf "ALTER SYSTEM SET primary_conninfo = :'conninfo';\nSELECT pg_reload_conf();\n" \
    | compose exec -T -e NEW_HOST="$new_primary" "$other" sh -c \
        'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v conninfo="host=$NEW_HOST port=5432 user=$REPLICATION_USER password=$REPLICATION_PASSWORD application_name=$REPLICA_NAME"' >/dev/null
for _ in $(seq 1 60); do
    state="$(sql "$new_primary" -c "SELECT state FROM pg_stat_replication WHERE application_name = '${other_name}'")"
    [ "$state" = "streaming" ] && break
    sleep 1
done
[ "$state" = "streaming" ] || { echo "${other_name} не подключилась к ${new_primary}" >&2; exit 1; }
t_repointed="$(now)"
annotate "${other_name} переключена на ${new_name}"

if [ "$mode" = "sync" ]; then
    log "Кворумная репликация на новом мастере"
    PRIMARY_NODE="$new_primary" SYNC_STANDBYS="$other_name" "${here}/sync-mode.sh" sync
fi

log "Перенаправляю приложения на ${new_primary}"
# php и nginx пересоздаются вместе: у нового контейнера php другой IP, а nginx держит адрес апстрима, разрешённый при старте.
DB_PRIMARY_HOST="$new_primary" compose up -d --no-deps --force-recreate php-native php-symfony nginx-native nginx-symfony >/dev/null 2>&1
for _ in $(seq 1 30); do
    ok=1
    for port in "$native_port" "$symfony_port"; do
        [ "$(curl -s -o /dev/null -m 5 -w '%{http_code}' "http://127.0.0.1:${port}/user/get/${probe_id}")" = "200" ] || ok=0
    done
    [ "$ok" = 1 ] && break
    sleep 1
done
t_apps="$(now)"
annotate "Приложения переключены на ${new_name}"

# Хвост пробы чтения после переключения: убедиться, что чтение стабильно.
sleep 5
kill "$probe_pid" 2>/dev/null || true

# --- 6. Проверка потерь ---
log "Проверка потерь"
cat "${results}/native/acked.log" "${results}/symfony/acked.log" > "${results}/acked.ids"

# Список подтверждённых id передаётся прямо в запрос (VALUES): на реплике нельзя создавать даже временные таблицы,
# а запрос должен работать одинаково на любом узле.
[ -s "${results}/acked.ids" ] || { echo "Нет ни одной подтверждённой записи: нагрузка не отработала" >&2; exit 1; }
acked_values="$(sed "s/.*/('&'::uuid)/" "${results}/acked.ids" | paste -sd,)"

verify() {
    compose exec -T "$1" sh -c 'psql -X -q -tA -F, -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<SQL
WITH acked(id) AS (VALUES ${acked_values})
SELECT count(*), count(u.id), count(*) - count(u.id) FROM acked a LEFT JOIN users u USING (id);
WITH acked(id) AS (VALUES ${acked_values})
SELECT count(*) FROM users u
WHERE u.first_name = 'Load' AND u.last_name = 'Writer' AND u.created_at >= '${t_start}'
  AND NOT EXISTS (SELECT 1 FROM acked a WHERE a.id = u.id);
SQL
}
# Id потерянных записей (подтверждены клиенту, на новом мастере их нет).
compose exec -T "$new_primary" sh -c 'psql -X -q -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "${results}/lost.ids" <<SQL
WITH acked(id) AS (VALUES ${acked_values})
SELECT a.id FROM acked a LEFT JOIN users u USING (id) WHERE u.id IS NULL ORDER BY a.id;
SQL
mapfile -t verdict < <(verify "$new_primary")
IFS=, read -r acked present lost <<< "${verdict[0]}"
unacked_committed="${verdict[1]}"
echo "Подтверждено клиенту: ${acked}; найдено на новом мастере: ${present}; потеряно: ${lost}"
echo "Закоммичено, но без ответа клиенту (оборванные запросы): ${unacked_committed}"

# Вторая реплика должна догнать нового мастера и иметь те же данные.
sleep 2
mapfile -t verdict_other < <(verify "$other")
IFS=, read -r _ present_other lost_other <<< "${verdict_other[0]}"
echo "На ${other_name}: найдено ${present_other}, потеряно ${lost_other}"

log "Проверка работы после failover"
post_ok=0
for port in "$native_port" "$symfony_port"; do
    code="$(curl -s -o /tmp/failover-register.json -m 10 -w '%{http_code}' -H 'Content-Type: application/json' "http://127.0.0.1:${port}/user/register" \
        -d '{"firstName":"Post","lastName":"Failover","birthDate":"1990-01-01","gender":"male","interests":[],"city":"Moscow","password":"post-failover"}')"
    echo "register через порт ${port}: HTTP ${code}"
    [ "$code" = "201" ] && post_ok=$((post_ok + 1))
done

# --- Итоги ---
python3 - "$results" "$mode" "$delay" "$vus" "$duration" "$kill_after" "$new_name" "$other_name" \
    "$acked" "$present" "$lost" "$unacked_committed" "$present_other" "$lost_other" "$post_ok" \
    "$t_kill" "$t_load_end" "$t_promote" "$t_promoted" "$t_repointed" "$t_apps" <<'PY'
import json, sys, collections
(results, mode, delay, vus, duration, kill_after, new_name, other_name, acked, present, lost, unacked,
 present_other, lost_other, post_ok, t_kill, t_load_end, t_promote, t_promoted, t_repointed, t_apps) = sys.argv[1:]
t_kill, t_load_end, t_promote, t_promoted, t_repointed, t_apps = map(float, (t_kill, t_load_end, t_promote, t_promoted, t_repointed, t_apps))

apps = {}
for app in ("native", "symfony"):
    d = json.load(open(f"{results}/{app}/write-summary.json"))["metrics"]
    codes = {}
    apps[app] = {
        "requests": int(d["http_reqs"]["values"]["count"]),
        "acked": int(d.get("acked_registrations", {"values": {"count": 0}})["values"]["count"]),
        "failed_pct": d["http_req_failed"]["values"]["rate"] * 100,
        "p95": d["http_req_duration"]["values"]["p(95)"],
    }

before = collections.Counter(); during = collections.Counter(); after = collections.Counter()
for line in open(f"{results}/read-probe.log"):
    parts = line.split()
    if len(parts) != 2:
        continue
    t, status = float(parts[0]), parts[1]
    bucket = before if t < t_kill else (during if t < t_apps else after)
    bucket[status] += 1

def fmt(counter):
    return ", ".join(f"HTTP {k}: {v}" for k, v in sorted(counter.items())) or "нет проб"

lines = [
    f"# Эксперимент: авария мастера, режим {mode}",
    "",
    "| Параметр | Значение |", "|---|---|",
    f"| Режим репликации | {'асинхронный' if mode == 'async' else 'кворумная синхронная ANY 1 (replica1, replica2)'} |",
    f"| Задержка сети primary → реплики (netem) | {delay} |",
    f"| Нагрузка на запись | {vus} VU на приложение (native и symfony), {duration} с, kill на {kill_after}-й секунде |",
    f"| Новый мастер | {new_name} (самая свежая реплика по pg_last_wal_receive_lsn) |",
    "",
    "## Результат",
    "",
    "| Показатель | Значение |", "|---|---|",
    f"| Записей подтверждено клиентам (HTTP 201) | **{acked}** |",
    f"| Из них найдено на новом мастере | **{present}** |",
    f"| **Потеряно подтверждённых транзакций** | **{lost}** |",
    f"| На второй реплике ({other_name}) найдено / потеряно | {present_other} / {lost_other} |",
    f"| Закоммичено, но клиент ответа не получил (оборванные запросы) | {unacked} |",
    f"| Регистрация после failover через оба приложения | {post_ok} из 2 успешно |",
    "",
    "## Нагрузка на запись по приложениям", "",
    "| Приложение | Запросов | Подтверждено | Ошибок, % | p95, мс |", "|---|---|---|---|---|",
] + [f"| {a} | {v['requests']} | {v['acked']} | {v['failed_pct']:.1f} | {v['p95']:.0f} |" for a, v in apps.items()] + [
    "",
    "## Чтение во время аварии (GET /user/get/{id} через Symfony, проба раз в 0,5 с)", "",
    "| Интервал | Ответы |", "|---|---|",
    f"| до kill | {fmt(before)} |",
    f"| от kill до переключения приложений | {fmt(during)} |",
    f"| после переключения | {fmt(after)} |",
    "",
    "## Хронология (секунды от kill)", "",
    "| Событие | t, с |", "|---|---|",
    "| kill -9 primary | 0.0 |",
    f"| нагрузка на запись остановлена | {t_load_end - t_kill:.1f} |",
    f"| начат promote | {t_promote - t_kill:.1f} |",
    f"| promote завершён (длился {t_promoted - t_promote:.1f} с) | {t_promoted - t_kill:.1f} |",
    f"| {other_name} переключена и подключена к новому мастеру | {t_repointed - t_kill:.1f} |",
    f"| приложения перенаправлены и отвечают | {t_apps - t_kill:.1f} |",
]
open(f"{results}/summary.md", "w").write("\n".join(lines) + "\n")
print("\n".join(lines))
PY

log "Готово. Итоги: ${results}/summary.md"
echo "Прежний primary остановлен. Для следующего эксперимента: make repl-rebuild"
