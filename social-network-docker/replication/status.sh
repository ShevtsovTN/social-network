#!/usr/bin/env bash
# Состояние репликации: роль каждого узла, подключённые реплики и лаг (стек поднят, `make up` или `make bench-up`).
# Узлы по умолчанию: postgres postgres-replica-1 postgres-replica-2; список можно задать через NODES.
set -euo pipefail

nodes="${NODES:-postgres postgres-replica-1 postgres-replica-2}"

psql_node() {
    local node="$1"
    shift
    docker compose exec -T "$node" sh -c 'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' -- "$@"
}

echo "== Роли узлов =="
for node in $nodes; do
    role="$(psql_node "$node" -tA -c "SELECT CASE WHEN pg_is_in_recovery() THEN 'standby (read-only)' ELSE 'primary' END" 2>/dev/null || echo 'недоступен')"
    printf '%-20s %s\n' "$node" "$role"
done

for node in $nodes; do
    if [ "$(psql_node "$node" -tA -c 'SELECT NOT pg_is_in_recovery()' 2>/dev/null || echo f)" = "t" ]; then
        echo
        echo "== Реплики, подключённые к ${node} (pg_stat_replication) =="
        psql_node "$node" -c "
            SELECT application_name AS replica, client_addr, state, sync_state,
                   pg_current_wal_lsn() AS primary_lsn, replay_lsn,
                   pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS lag_bytes,
                   coalesce(replay_lag::text, '-') AS replay_lag
            FROM pg_stat_replication ORDER BY application_name"
        psql_node "$node" -c "SHOW synchronous_standby_names" -c "SHOW synchronous_commit"
    fi
done

echo
echo "== Получение и применение WAL на репликах =="
for node in $nodes; do
    if [ "$(psql_node "$node" -tA -c 'SELECT pg_is_in_recovery()' 2>/dev/null || echo f)" = "t" ]; then
        printf '%-20s ' "$node"
        psql_node "$node" -tA -F ' ' -c "SELECT 'received=' || pg_last_wal_receive_lsn(), 'replayed=' || pg_last_wal_replay_lsn()"
    fi
done
