#!/usr/bin/env bash
# «Эффект Леди Гаги»: как распределяются сообщения по воркерам при ключе dialog_id и каким было бы распределение при
# ключе sender_id. Для сравнения создаётся временная копия таблицы, распределённая по sender_id (в своей группе
# колокации, чтобы не влиять на шарды диалогов), и удаляется после замера.
# Стек поднят, диалоги засеяны (make seed-dialogs). Итог: docs/homework-sharding/results/skew.txt.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
results="${root}/docs/homework-sharding/results"

mkdir -p "$results"
(cd "$root" && docker compose exec -T dialogs-coordinator sh -c \
    'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f -') < "${here}/dialog-skew.sql" 2>&1 | tee "${results}/skew.txt"
