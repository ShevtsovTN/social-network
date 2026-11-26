#!/usr/bin/env bash
# Заполняет диалоги тестовыми сообщениями с перекосом (ДЗ «Шардинг диалогов», модель — в dialogs.sql).
# Стек поднят, users основной БД заполнена (make seed). Участники — 100 000 её пользователей (воспроизводимая выборка).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$root"

docker compose exec -T postgres sh -c \
    'psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "COPY (SELECT id FROM users ORDER BY md5(id::text) LIMIT 100000) TO STDOUT"' \
    | docker compose exec -T dialogs-coordinator sh -c \
        'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /seed/dialogs.sql'
