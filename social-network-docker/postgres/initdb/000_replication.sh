#!/bin/sh
# Подготовка primary к потоковой репликации. Выполняется один раз, при инициализации пустого тома
# (только на primary: реплики получают данные через pg_basebackup и этот каталог не монтируют).
set -eu

: "${REPLICATION_USER:?не задан REPLICATION_USER}"
: "${REPLICATION_PASSWORD:?не задан REPLICATION_PASSWORD}"

# Отдельная роль только с правом репликации: подключиться к данным под ней нельзя.
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres \
    -v replication_user="$REPLICATION_USER" -v replication_password="$REPLICATION_PASSWORD" <<'SQL'
CREATE ROLE :"replication_user" WITH LOGIN REPLICATION PASSWORD :'replication_password';
SQL

# Подключения репликации в pg_hba.conf нужно разрешать отдельной строкой: «all» в поле базы их не покрывает.
echo "host replication ${REPLICATION_USER} all scram-sha-256" >> "${PGDATA}/pg_hba.conf"
