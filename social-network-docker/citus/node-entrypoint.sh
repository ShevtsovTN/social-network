#!/bin/sh
# Entrypoint узла Citus (координатор и воркеры). Узлы подключаются друг к другу под тем же пользователем:
# координатор выполняет запросы на воркерах, а при переносе шарда узел-приёмник подписывается на узел-источник.
# Пароль для этих подключений libpq берёт из .pgpass пользователя postgres: он пишется из окружения при каждом
# старте и не попадает ни в образ, ни в метаданные кластера. Затем запускается штатный docker-entrypoint.sh.
set -eu

: "${POSTGRES_USER:?не задан POSTGRES_USER}"
: "${POSTGRES_PASSWORD:?не задан POSTGRES_PASSWORD}"

# В .pgpass двоеточие и обратная косая черта экранируются обратной косой чертой.
escape() { printf '%s' "$1" | sed 's/[\\:]/\\&/g'; }

passfile=/var/lib/postgresql/.pgpass
(
    umask 077
    printf '*:5432:*:%s:%s\n' "$(escape "$POSTGRES_USER")" "$(escape "$POSTGRES_PASSWORD")" > "$passfile"
)
chown postgres:postgres "$passfile"

exec docker-entrypoint.sh "$@"
