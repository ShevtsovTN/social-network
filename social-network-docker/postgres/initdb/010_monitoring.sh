#!/bin/sh
# Роль для postgres_exporter: только чтение статистики (pg_monitor), данные пользователей ей недоступны.
# Выполняется один раз, при инициализации пустого тома primary. Роли реплицируются, поэтому на репликах
# отдельной настройки нет. Для уже созданного тома скрипт можно выполнить вручную (см. README, раздел мониторинга).
set -eu

: "${MONITORING_USER:?не задан MONITORING_USER}"
: "${MONITORING_PASSWORD:?не задан MONITORING_PASSWORD}"

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres \
    -v monitoring_user="$MONITORING_USER" -v monitoring_password="$MONITORING_PASSWORD" <<'SQL'
CREATE ROLE :"monitoring_user" WITH LOGIN PASSWORD :'monitoring_password';
GRANT pg_monitor TO :"monitoring_user";
SQL
