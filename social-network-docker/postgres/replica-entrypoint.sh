#!/bin/sh
# Entrypoint реплики (standby). На пустом томе снимает базовую копию с primary и запускает штатный
# docker-entrypoint.sh; на непустом (перезапуск) сразу запускает PostgreSQL.
#
# pg_basebackup -R создаёт standby.signal и записывает primary_conninfo в postgresql.auto.conf:
# так узел стартует в режиме восстановления и непрерывно получает WAL с primary (потоковая репликация).
# application_name в primary_conninfo нужен для кворумной синхронной репликации:
# primary опознаёт реплику по имени из synchronous_standby_names.
set -eu

: "${PRIMARY_HOST:?не задан PRIMARY_HOST}"
: "${REPLICA_NAME:?не задан REPLICA_NAME}"
: "${REPLICATION_USER:?не задан REPLICATION_USER}"
: "${REPLICATION_PASSWORD:?не задан REPLICATION_PASSWORD}"

if [ ! -s "${PGDATA}/PG_VERSION" ]; then
    echo "Каталог данных пуст: снимаю базовую копию с ${PRIMARY_HOST}"
    mkdir -p "$PGDATA"
    chown postgres:postgres "$PGDATA"
    chmod 700 "$PGDATA"

    until pg_isready -q -h "$PRIMARY_HOST" -p 5432; do
        echo "Жду primary ${PRIMARY_HOST}..."
        sleep 2
    done

    gosu postgres pg_basebackup \
        -d "host=${PRIMARY_HOST} port=5432 user=${REPLICATION_USER} password=${REPLICATION_PASSWORD} application_name=${REPLICA_NAME}" \
        -D "$PGDATA" \
        --wal-method=stream \
        --write-recovery-conf \
        --progress --verbose
fi

exec docker-entrypoint.sh "$@"
