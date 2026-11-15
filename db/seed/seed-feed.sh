#!/usr/bin/env bash
# Заполняет пустые friendships и posts (ДЗ «Лента постов»): стек поднят, users заполнена (make seed).
# Тексты постов — db/seed/posts.txt (make seed-posts-file), путь можно передать первым аргументом.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
texts="${1:-${here}/posts.txt}"

[ -f "$texts" ] || { echo "Нет файла ${texts}: выполните make seed-posts-file." >&2; exit 1; }

cat "$texts" | docker compose exec -T postgres sh -c \
    'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /seed/feed.sql'
