#!/usr/bin/env bash
# Пересобирает кластер диалогов с нуля: удаляет контейнеры и тома Citus (ВСЕ сообщения диалогов пропадают), поднимает
# координатор и два воркера и засевает диалоги заново. Нужен перед повторным прогоном решардинга: после него в кластере
# четыре воркера и разрезанный шард. Основной PostgreSQL, Redis и приложения не затрагиваются.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
cd "$root"

compose() { docker compose -f docker-compose.yaml --profile reshard "$@"; }
project="$(compose config --format json | sed -n 's/^  "name": "\(.*\)",$/\1/p')"
[ -n "$project" ] || { echo "Не удалось определить имя проекта compose." >&2; exit 1; }

echo "Удаляю кластер диалогов и его тома"
compose rm -sf dialogs-init dialogs-coordinator dialogs-worker-1 dialogs-worker-2 dialogs-worker-3 dialogs-worker-4 >/dev/null
for node in coordinator worker-1 worker-2 worker-3 worker-4; do
    docker volume rm -f "${project}_pgdata-dialogs-${node}" >/dev/null
done

echo "Поднимаю кластер"
compose up -d dialogs-coordinator dialogs-worker-1 dialogs-worker-2 dialogs-init >/dev/null 2>&1
# docker wait, а не compose wait: тот завершается ошибкой, если контейнер уже успел отработать.
[ "$(docker wait "$(compose ps -aq dialogs-init)")" = "0" ] \
    || { compose logs dialogs-init >&2; echo "Кластер диалогов не инициализировался." >&2; exit 1; }

echo "Засеваю диалоги"
"${root}/db/dialogs/seed/seed-dialogs.sh"
