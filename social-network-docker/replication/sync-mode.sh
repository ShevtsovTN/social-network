#!/usr/bin/env bash
# Переключает режим репликации на primary: async (по умолчанию в кластере) или sync (кворумная синхронная).
#
#   sync   synchronous_standby_names = 'ANY <QUORUM> (<SYNC_STANDBYS>)': коммит подтверждается клиенту только после
#          того, как WAL записан на диск минимум на QUORUM реплик из списка. Какие именно, неважно: поэтому потеря
#          одной реплики не останавливает запись, а подтверждённый коммит всегда есть хотя бы на одной из выживших.
#          synchronous_commit = on (значение по умолчанию): ждём fsync WAL на реплике, не применения.
#   async  список пуст: коммит подтверждается сразу после fsync на primary, реплики догоняют потоком.
#
# Параметры через окружение: PRIMARY_NODE (сервис compose с primary, по умолчанию postgres),
# SYNC_STANDBYS ("replica1, replica2": application_name реплик), QUORUM (1).
# Настройка пишется ALTER SYSTEM в postgresql.auto.conf primary и применяется без перезапуска (pg_reload_conf).
set -euo pipefail

mode="${1:?укажите режим: sync или async}"
primary="${PRIMARY_NODE:-postgres}"
standbys="${SYNC_STANDBYS:-replica1, replica2}"
quorum="${QUORUM:-1}"

psql_primary() {
    docker compose exec -T "$primary" sh -c 'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' -- "$@"
}

case "$mode" in
    sync)
        psql_primary -c "ALTER SYSTEM SET synchronous_standby_names = 'ANY ${quorum} (${standbys})'" -c "SELECT pg_reload_conf()" >/dev/null
        expected="quorum"
        ;;
    async)
        psql_primary -c "ALTER SYSTEM RESET synchronous_standby_names" -c "SELECT pg_reload_conf()" >/dev/null
        expected="async"
        ;;
    *)
        echo "Неизвестный режим: ${mode} (sync или async)" >&2
        exit 1
        ;;
esac

# Конфигурация применяется асинхронно: ждём, пока pg_stat_replication покажет нужное состояние у всех реплик.
for _ in $(seq 1 20); do
    states="$(psql_primary -tA -c "SELECT string_agg(application_name || '=' || sync_state, ' ' ORDER BY application_name) FROM pg_stat_replication")"
    if [ -n "$states" ] && ! printf '%s' "$states" | tr ' ' '\n' | grep -v "=${expected}$" | grep -q .; then
        echo "Режим ${mode}: ${states}"
        psql_primary -tA -c "SHOW synchronous_standby_names" | sed 's/^$/(пусто)/; s/^/synchronous_standby_names = /'
        exit 0
    fi
    sleep 0.5
done

echo "Режим ${mode} не применился за 10 с: ${states:-реплики не подключены}" >&2
exit 1
